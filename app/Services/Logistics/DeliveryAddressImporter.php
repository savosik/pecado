<?php

namespace App\Services\Logistics;

use App\Models\Company;
use App\Models\DeliveryAddress;
use App\Models\User;
use App\Services\DaData\DaDataClient;
use App\Services\DaData\DaDataException;
use App\Support\Crm\ClientNameIndex;
use App\Support\Logistics\AddressCleaner;
use App\Support\Logistics\CarrierDetector;
use App\Support\Logistics\DeliveryAddressImportReport;
use App\Support\Logistics\ShipmentRow;
use Illuminate\Support\Facades\DB;

/**
 * Восстановление справочника адресов доставки из таблицы заданий логисту.
 *
 * Клиенты почти не заполняют адреса в кабинете (на момент импорта — 23 адреса на
 * 846 кабинетов), хотя отдел продаж три года пишет их в таблицу логиста при каждой
 * отгрузке. Импорт переносит их обратно в кабинет: адрес, по которому мы реально
 * возили, — самый достоверный из доступных.
 *
 * Контрагент опознаётся сначала по ИНН из блока «Получатель», и только потом по
 * имени через {@see ClientNameIndex}. Совпадение засчитывается, лишь когда
 * кандидат ровно один: чужой адрес в кабинете хуже пустого справочника — по нему
 * уедет заказ.
 */
class DeliveryAddressImporter
{
    /** ИНН: 10 цифр у организации, 12 у предпринимателя. */
    private const TAX_ID = '/\b(\d{10}|\d{12})\b/';

    /** Разумный потолок на кабинет: список из тридцати адресов пользоваться мешает. */
    public const DEFAULT_MAX_PER_CLIENT = 10;

    /** @var array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>|null}> Ответы DaData за прогон: один адрес встречается в таблице десятки раз. */
    private array $suggestions = [];

    public function __construct(private readonly DaDataClient $daData) {}

    /**
     * @param  list<ShipmentRow>  $rows
     * @param  bool  $dryRun  только посчитать, ничего не записывая
     */
    public function import(array $rows, bool $dryRun = false, int $maxPerClient = self::DEFAULT_MAX_PER_CLIENT): DeliveryAddressImportReport
    {
        $report = new DeliveryAddressImportReport;
        $report->rowsRead = count($rows);

        $candidates = $this->collect($rows, $report);

        foreach ($candidates as $userId => $addresses) {
            $this->applyToClient($userId, $addresses, $dryRun, $maxPerClient, $report);
        }

        return $report;
    }

    /**
     * Строки таблицы → адреса, сгруппированные по кабинету.
     *
     * Один и тот же адрес встречается в таблице десятками строк — из них
     * сохраняются число отгрузок и последний год: по ним потом решается, какие
     * адреса вообще заводить и какой из них сделать основным.
     *
     * @param  list<ShipmentRow>  $rows
     * @return array<int, array<string, array{address: string, count: int, last_year: int, carrier: ?string, matched_by: string}>>
     */
    private function collect(array $rows, DeliveryAddressImportReport $report): array
    {
        [$taxIds, $names] = $this->clientIndex();
        $candidates = [];

        foreach ($rows as $row) {
            if (! $row->hasAddress()) {
                $report->rowsWithoutAddress++;

                continue;
            }

            if (CarrierDetector::isSelfPickup($row->delivery, $row->address)) {
                $report->rowsSelfPickup++;

                continue;
            }

            $carrier = CarrierDetector::detect($row->delivery, $row->address);

            if (! AddressCleaner::looksLikeAddress($row->address, $carrier !== null)) {
                $report->rowsWithoutAddress++;

                continue;
            }

            [$userId, $matchedBy, $candidateCount] = $this->matchClient($row, $taxIds, $names);

            if ($userId === null) {
                if ($candidateCount > 1) {
                    $report->addAmbiguous($row->client, $row->sheet, $row->line, $candidateCount);
                } else {
                    $report->addUnmatched($row->client, $row->sheet, $row->line, $row->address);
                }

                continue;
            }

            $matchedBy === 'inn' ? $report->matchedByTaxId++ : $report->matchedByName++;

            $address = $this->cleanAddress($row->address);
            $key = AddressCleaner::key($address);

            if ($key === '') {
                continue;
            }

            $existing = $candidates[$userId][$key] ?? null;

            $candidates[$userId][$key] = [
                // Написание берём самое подробное: в таблице один адрес пишут и
                // «Москва, ул Горчакова д 5», и «Москва, ул Горчакова, д. 5, офис 3».
                'address' => $existing !== null && mb_strlen($existing['address']) >= mb_strlen($address)
                    ? $existing['address']
                    : $address,
                'count' => ($existing['count'] ?? 0) + 1,
                'last_year' => max($existing['last_year'] ?? 0, $row->year),
                'carrier' => $existing['carrier'] ?? $carrier,
                'matched_by' => $existing['matched_by'] ?? $matchedBy,
            ];
        }

        return $candidates;
    }

    /**
     * Кабинет по строке таблицы.
     *
     * @param  array<string, list<int>>  $taxIds
     * @return array{0: ?int, 1: string, 2: int} кабинет, способ опознания, число кандидатов
     */
    private function matchClient(ShipmentRow $row, array $taxIds, ClientNameIndex $names): array
    {
        $byTaxId = [];

        if (preg_match_all(self::TAX_ID, $row->recipient, $matches)) {
            foreach ($matches[1] as $taxId) {
                foreach ($taxIds[$taxId] ?? [] as $userId) {
                    $byTaxId[$userId] = true;
                }
            }
        }

        if (count($byTaxId) === 1) {
            return [(int) array_key_first($byTaxId), 'inn', 1];
        }

        // Несколько кабинетов на один ИНН — это ошибка данных, а не повод гадать.
        if (count($byTaxId) > 1) {
            return [null, 'inn', count($byTaxId)];
        }

        $byName = $names->find($row->client);

        return count($byName) === 1
            ? [(int) $byName[0], 'name', 1]
            : [null, 'name', count($byName)];
    }

    /**
     * Адреса одного кабинета: отсеять уже заведённые и записать остальные.
     *
     * @param  array<string, array{address: string, count: int, last_year: int, carrier: ?string, matched_by: string}>  $addresses
     */
    private function applyToClient(int $userId, array $addresses, bool $dryRun, int $maxPerClient, DeliveryAddressImportReport $report): void
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return;
        }

        // Свежие и частые адреса — вперёд: обрезка лимитом должна отсекать
        // разовые отгрузки трёхлетней давности, а не рабочий склад клиента.
        uasort($addresses, fn (array $a, array $b): int => [$b['last_year'], $b['count']] <=> [$a['last_year'], $a['count']]);

        $existing = DeliveryAddress::withoutGlobalScopes()->where('user_id', $userId)->get();
        $known = [];

        foreach ($existing as $address) {
            $data = is_array($address->address_data) ? $address->address_data : null;

            foreach ($this->keysOf($address->address, $address->address, $data) as $key) {
                $known[$key] = true;
            }
        }

        $usedNames = $existing->pluck('name')->all();
        $slots = max(0, $maxPerClient - $existing->count());
        $hasDefault = $existing->contains(fn (DeliveryAddress $address): bool => (bool) $address->is_default);

        foreach ($addresses as $candidate) {
            if ($slots === 0) {
                $report->addressesTrimmed++;

                continue;
            }

            [$suggestion, $hint] = $this->suggest($candidate['address'], $report);
            $data = $suggestion === null ? null : (is_array($suggestion['data'] ?? null) ? $suggestion['data'] : null);

            // Разобранным считается только адрес с домом. Разбор до города —
            // это не адрес: «терминал в г.Самара» DaData сводит к случайному
            // микрорайону, и такой ФИАС в кабинете вреднее пустого поля.
            $address = $data !== null
                ? $this->withDetails((string) ($suggestion['unrestricted_value'] ?? $suggestion['value'] ?? ''), $candidate['address'])
                : $candidate['address'];

            $hintData = is_array($hint['data'] ?? null) ? $hint['data'] : null;
            $city = $this->cityOf($data) ?: ($this->cityOf($hintData) ?: $this->cityFromText($candidate['address']));
            $keys = $this->keysOf($candidate['address'], $address, $data);

            // Один терминал перевозчика в городе — одна запись: в таблице к нему
            // приписывают то «за наш счёт», то название магазина-получателя, и
            // без этого кабинет заполняется четырьмя «Терминал ПЭК, Курск».
            if ($candidate['carrier'] !== null && $city !== '') {
                $keys[] = 'carrier:'.$candidate['carrier'].'|'.mb_strtolower($city);
            }

            if (array_intersect_key($known, array_flip($keys)) !== []) {
                $report->addressesAlreadyPresent++;

                continue;
            }

            $name = $this->uniqueName($this->composeName($candidate, $data, $address, $city), $usedNames);
            $usedNames[] = $name;

            foreach ($keys as $key) {
                $known[$key] = true;
            }

            $slots--;
            $report->addressesCreated++;
            $report->created[] = [
                'user_id' => $userId,
                'user' => (string) ($user->erp_name ?: $user->name),
                'name' => $name,
                'address' => $address,
                'carrier' => $candidate['carrier'],
                'shipments' => $candidate['count'],
                'last_year' => $candidate['last_year'],
                'matched_by' => $candidate['matched_by'],
                'parsed' => $data !== null,
            ];

            // Основным делаем только настоящий адрес клиента: терминал перевозчика
            // в предвыборе на оформлении заказа — прямой путь к отгрузке не туда.
            $makeDefault = ! $hasDefault && $candidate['carrier'] === null;

            if ($makeDefault) {
                $hasDefault = true;
            }

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($userId, $name, $address, $data, $makeDefault): void {
                DeliveryAddress::withoutGlobalScopes()->create([
                    'user_id' => $userId,
                    'name' => $name,
                    'address' => $address,
                    'address_data' => $data,
                    'is_default' => $makeDefault,
                ]);
            });
        }
    }

    /**
     * Разбор адреса геокодером.
     *
     * Варианты запроса перебираются от самого полного к обрезанному: в хвосте
     * строки менеджеры дописывают «пав 15» и «КПП 6», из-за которых DaData не
     * находит дом, а без хвоста находит.
     *
     * Возвращается пара: разбор с домом (или null) и черновой разбор — он дома
     * не даёт, но из него виден город, а по городу подписывается терминал.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function suggest(string $address, DeliveryAddressImportReport $report): array
    {
        $cacheKey = AddressCleaner::key($address);

        if (array_key_exists($cacheKey, $this->suggestions)) {
            return $this->suggestions[$cacheKey];
        }

        $hint = null;

        foreach (AddressCleaner::queryVariants($address) as $query) {
            try {
                $suggestions = $this->daData->suggestAddress($query, 1);
            } catch (DaDataException) {
                $report->daDataFailures++;

                return $this->suggestions[$cacheKey] = [null, $hint];
            }

            $suggestion = $suggestions[0] ?? null;

            if ($suggestion === null) {
                continue;
            }

            if (($suggestion['data']['house'] ?? null) !== null) {
                return $this->suggestions[$cacheKey] = [$suggestion, $suggestion];
            }

            $hint ??= $suggestion;
        }

        return $this->suggestions[$cacheKey] = [null, $hint];
    }

    /**
     * Ключи, по которым адрес считается тем же самым.
     *
     * Ключей несколько, потому что сравнивать приходится разное: исходную запись
     * из таблицы, итоговую строку в кабинете и — для разобранных адресов — ФИАС
     * дома вместе с офисом (иначе два офиса в одном здании слипнутся в один).
     *
     * @param  array<string, mixed>|null  $data
     * @return list<string>
     */
    private function keysOf(string $raw, string $address, ?array $data): array
    {
        $keys = [AddressCleaner::key($raw), AddressCleaner::key($address)];

        if ($data !== null && ($data['fias_id'] ?? null) !== null) {
            $keys[] = 'fias:'.$data['fias_id'].'|'.($data['flat'] ?? '').'|'.AddressCleaner::details($address);
        }

        return array_values(array_unique(array_filter($keys, fn (string $key): bool => $key !== '')));
    }

    /**
     * Вернуть в разобранный адрес детали, которые геокодер не понимает.
     */
    private function withDetails(string $address, string $raw): string
    {
        $details = AddressCleaner::details($raw);

        if ($details === '' || mb_stripos($address, $details) !== false) {
            return $address;
        }

        // Офис DaData иногда разбирает сама — второй раз дописывать не нужно.
        $missing = array_filter(
            explode(', ', $details),
            fn (string $detail): bool => mb_stripos($address, $detail) === false,
        );

        return $missing === [] ? $address : $address.', '.implode(', ', $missing);
    }

    private function cleanAddress(string $address): string
    {
        $address = str_replace(["\u{00A0}", "\n", "\r", "\t"], ' ', $address);

        return trim((string) preg_replace('/\s+/u', ' ', $address), ' ,;.');
    }

    /**
     * Подпись адреса в кабинете — то, что клиент видит заголовком карточки.
     *
     * @param  array{address: string, count: int, last_year: int, carrier: ?string, matched_by: string}  $candidate
     * @param  array<string, mixed>|null  $data
     */
    private function composeName(array $candidate, ?array $data, string $address, string $city): string
    {
        if ($candidate['carrier'] !== null) {
            $carrier = $candidate['carrier'] === 'ТК' ? 'Терминал ТК' : 'Терминал '.$candidate['carrier'];

            return $city === '' ? $carrier : $carrier.', '.$city;
        }

        if ($data !== null) {
            $parts = array_filter([
                (string) ($data['city_with_type'] ?? $data['settlement_with_type'] ?? $data['region_with_type'] ?? ''),
                (string) ($data['street_with_type'] ?? ''),
                ($data['house'] ?? null) !== null ? trim(($data['house_type'] ?? 'д').' '.$data['house']) : '',
            ], fn (string $part): bool => $part !== '');

            if ($parts !== []) {
                return mb_substr(implode(', ', $parts), 0, 255);
            }
        }

        // Неразобранный адрес: в заголовок идёт вычищенная строка без поручений
        // логисту — «ЗА СЧЕТ КЛИЕНТА: до пункта выдачи…» заголовком быть не может.
        $fallback = AddressCleaner::tidy($candidate['address']);

        return $this->shorten($fallback === '' ? $address : $fallback, 80);
    }

    /**
     * Заголовок карточки: длинную строку рвём по границе слова, а не посередине.
     */
    private function shorten(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $limit / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,.-').'…';
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function cityOf(?array $data): string
    {
        if ($data === null) {
            return '';
        }

        return (string) ($data['city'] ?? $data['settlement'] ?? $data['region'] ?? '');
    }

    /**
     * Город из свободного текста: «терминал в г.Самара» DaData разбирает не всегда.
     */
    private function cityFromText(string $address): string
    {
        // Города через дефис пишутся с заглавных в каждой части — «Ростов-на-Дону»,
        // «Санкт-Петербург», — и обрывать их на первом дефисе нельзя.
        $city = '[А-ЯЁ][а-яё]+(?:-[А-Яа-яЁё][а-яё]*)*';

        if (preg_match('/\bг(?:ород)?\.?\s*('.$city.')/u', $address, $matches)) {
            return $matches[1];
        }

        return preg_match('/терминал\w*\s+(?:в\s+)?('.$city.')/u', $address, $matches) ? $matches[1] : '';
    }

    /**
     * @param  list<string>  $used
     */
    private function uniqueName(string $name, array $used): string
    {
        if (! in_array($name, $used, true)) {
            return $name;
        }

        for ($suffix = 2; $suffix <= 50; $suffix++) {
            $candidate = mb_substr($name, 0, 250).' ('.$suffix.')';

            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        return $name;
    }

    /**
     * Клиенты, разложенные по ИНН их компаний и по всем известным именам.
     *
     * @return array{0: array<string, list<int>>, 1: ClientNameIndex}
     */
    private function clientIndex(): array
    {
        $taxIds = [];
        $names = new ClientNameIndex;

        Company::query()
            ->select('user_id', 'name', 'legal_name', 'tax_id')
            ->whereNotNull('user_id')
            ->each(function (Company $company) use (&$taxIds, $names): void {
                $userId = (int) $company->user_id;
                $taxId = trim((string) $company->tax_id);

                if ($taxId !== '' && ! in_array($userId, $taxIds[$taxId] ?? [], true)) {
                    $taxIds[$taxId][] = $userId;
                }

                $names->add($userId, (string) $company->name, (string) $company->legal_name);
            });

        User::query()
            ->select('id', 'name', 'erp_name')
            ->each(function (User $user) use ($names): void {
                $names->add((int) $user->getKey(), (string) $user->name, (string) $user->erp_name);
            });

        return [$taxIds, $names];
    }
}
