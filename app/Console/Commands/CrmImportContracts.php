<?php

namespace App\Console\Commands;

use App\Enums\Crm\ContractForm;
use App\Enums\Crm\ContractPaymentTerms;
use App\Enums\Crm\ContractStatus;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCategory;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Импорт Google-таблицы «Реестр договоров» в реестр CRM.
 *
 * Лист таблицы → категория (по названию листа), строка → договор. Контрагент
 * и партнёр ищутся по нормализованному названию в `companies` и `users`; если
 * не нашлись — договор заводится с названием стороны текстом, чтобы менеджер
 * привязал юрлицо руками. Ненайденные попадают в отчёт.
 *
 * Повторный запуск безопасен: договор с тем же номером в той же категории
 * пропускается.
 */
class CrmImportContracts extends Command
{
    protected $signature = 'crm:import-contracts
        {file : Путь к .xlsx (экспорт Google-таблицы)}
        {--dry-run : Только разобрать и показать отчёт, ничего не писать}
        {--report= : Куда записать JSON-отчёт (по умолчанию storage/app/contracts-import-report.json)}';

    protected $description = 'Импорт реестра договоров из xlsx-таблицы менеджеров';

    /**
     * Название листа → категория реестра. Лист без соответствия создаёт
     * категорию по своему имени.
     */
    private const SHEET_CATEGORIES = [
        'ООО Пекадо' => 'ООО Пекадо',
        'ИП Елисеев П.А. (клиенты)' => 'ИП Елисеев П.А. (клиенты)',
        'ИП Кербер (клиенты)' => 'ИП Кербер (клиенты)',
        'ИП Кербер (дистры)' => 'ИП Кербер (дистры)',
        'ООО Пекадо Импорт)' => 'ООО Пекадо Импорт',
        'ООО Пекадо Импорт' => 'ООО Пекадо Импорт',
    ];

    /** @var array<string, array{id: int, user_id: int|null}> */
    private array $companyIndex = [];

    /** @var array<string, int> */
    private array $userIndex = [];

    /** @var array<string, mixed> */
    private array $report = [
        'created' => 0,
        'skipped_existing' => 0,
        'skipped_empty' => 0,
        'unmatched_contractors' => [],
        'unmatched_partners' => [],
        'unknown_statuses' => [],
        'rows' => [],
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->buildIndexes();

        $spreadsheet = IOFactory::load($path);

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $title = trim($sheet->getTitle());
            $categoryName = self::SHEET_CATEGORIES[$title] ?? $title;

            $this->info("Лист «{$title}» → категория «{$categoryName}»");

            $category = $dryRun
                ? (ContractCategory::query()->where('name', $categoryName)->first() ?? new ContractCategory(['name' => $categoryName]))
                : ContractCategory::query()->firstOrCreate(['name' => $categoryName], ['sort_order' => 90]);

            foreach ($this->rows($sheet) as $row) {
                $this->importRow($row, $category, $categoryName, $dryRun);
            }
        }

        $this->writeReport();

        $this->table(['Показатель', 'Значение'], [
            ['Создано', $this->report['created']],
            ['Пропущено (уже есть)', $this->report['skipped_existing']],
            ['Пропущено (пустые строки)', $this->report['skipped_empty']],
            ['Контрагент не найден', count($this->report['unmatched_contractors'])],
            ['Партнёр не найден', count($this->report['unmatched_partners'])],
            ['Неизвестный статус', count($this->report['unknown_statuses'])],
        ]);

        if ($dryRun) {
            $this->warn('Режим --dry-run: в базу ничего не записано.');
        }

        return self::SUCCESS;
    }

    /**
     * Строки листа в единой форме. Шапка ищется по заголовкам; у листа без шапки
     * («ИП Кербер (клиенты)» — формулы вместо заголовков) колонки берутся по позиции.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Worksheet $sheet): array
    {
        $grid = $sheet->toArray(null, true, true, false);
        $rows = [];
        $map = null;
        $start = 0;

        foreach ($grid as $index => $cells) {
            $headers = array_map(fn ($cell): string => Str::lower(trim((string) $cell)), $cells);
            $joined = implode('|', $headers);

            if (str_contains($joined, 'контрагент') || str_contains($joined, 'дистрибьютор')) {
                $map = $this->headerMap($headers);
                $start = $index + 1;
                break;
            }
        }

        if ($map === null) {
            // Лист «ИП Кербер (клиенты)»: A — счётчик, B — номер (формула),
            // C — партнёр, D — контрагент, E — оплата, F — статус.
            $map = ['number' => 1, 'partner' => 2, 'contractor' => 3, 'payment' => 4, 'status' => 5];
            $start = 0;
        }

        foreach (array_slice($grid, $start) as $cells) {
            $get = fn (string $key) => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';

            $rows[] = [
                'partner' => $get('partner'),
                'contractor' => $get('contractor'),
                'number' => $get('number'),
                'date' => $this->parseDate($cells[$map['date'] ?? -1] ?? null),
                'payment' => $get('payment'),
                'status' => $get('status'),
                'manager' => $get('manager'),
                'form' => $get('form'),
                'note' => $get('note'),
                'comment' => $get('comment'),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    private function headerMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                // Безымянная колонка между «Менеджер» и «скан/оригинал/эдо» — заметки.
                if (isset($map['manager']) && ! isset($map['note'])) {
                    $map['note'] = $index;
                }

                continue;
            }

            match (true) {
                str_starts_with($header, 'партнер'), str_starts_with($header, 'партнёр') => $map['partner'] = $index,
                str_contains($header, 'контрагент'), str_contains($header, 'дистрибьютор') => $map['contractor'] = $index,
                str_contains($header, 'номер') => $map['number'] = $index,
                str_contains($header, 'дата') => $map['date'] = $index,
                str_contains($header, 'оплат') => $map['payment'] = $index,
                str_contains($header, 'статус') => $map['status'] = $index,
                str_contains($header, 'менеджер') => $map['manager'] = $index,
                str_contains($header, 'скан'), str_contains($header, 'эдо') => $map['form'] = $index,
                str_contains($header, 'коммент') => $map['comment'] = $index,
                default => null,
            };
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importRow(array $row, ContractCategory $category, string $categoryName, bool $dryRun): void
    {
        $contractorName = $row['contractor'] !== '' ? $row['contractor'] : $row['partner'];
        $number = $this->cleanNumber($row['number']);

        if ($contractorName === '' || $number === '') {
            $this->report['skipped_empty']++;

            return;
        }

        $date = $row['date'] ?? $this->dateFromNumber($row['number']);

        $company = $this->matchCompany($contractorName);
        $partner = $this->matchUser($row['partner'] !== '' ? $row['partner'] : $contractorName);

        if ($company === null) {
            $this->report['unmatched_contractors'][] = $contractorName;
        }

        if ($partner === null && $company?->user_id === null) {
            $this->report['unmatched_partners'][] = $row['partner'] !== '' ? $row['partner'] : $contractorName;
        }

        [$status, $statusNote] = $this->parseStatus($row['status']);
        $form = $this->parseForm($row['form']);
        $manager = $this->matchManager($row['manager']);

        $comments = array_filter([
            $row['comment'],
            $row['note'],
            $statusNote,
            $form === null && $row['form'] !== '' ? 'Форма: '.$row['form'] : null,
            $company === null ? 'Контрагент из таблицы: '.$contractorName : null,
            $row['partner'] !== '' && $row['partner'] !== $contractorName ? 'Партнёр из таблицы: '.$row['partner'] : null,
        ]);

        $exists = $category->exists && Contract::query()
            ->where('category_id', $category->getKey())
            ->where('number', $number)
            ->exists();

        $this->report['rows'][] = [
            'category' => $categoryName,
            'number' => $number,
            'contractor' => $contractorName,
            'company_id' => $company?->getKey(),
            'user_id' => $partner?->getKey() ?? $company?->user_id,
            'status' => $status->value,
            'exists' => $exists,
        ];

        if ($exists) {
            $this->report['skipped_existing']++;

            return;
        }

        $this->report['created']++;

        if ($dryRun) {
            return;
        }

        Contract::query()->create([
            'category_id' => $category->getKey(),
            'company_id' => $company?->getKey(),
            'user_id' => $partner?->getKey() ?? $company?->user_id,
            'counterparty_name' => $company === null ? $contractorName : null,
            'number' => $number,
            'date' => $date,
            // Дата подписания в таблице не велась: у подписанных берём дату договора,
            // иначе форма потом потребует её при каждой правке.
            'signed_at' => $status === ContractStatus::SIGNED ? $date : null,
            'status' => $status,
            'payment_terms' => $this->parsePayment($row['payment']),
            'form' => $form,
            'responsible_manager_id' => $manager?->getKey(),
            'comment' => $comments === [] ? null : implode("\n", $comments),
        ]);
    }

    private function buildIndexes(): void
    {
        Company::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->select('id', 'user_id', 'name', 'legal_name')
            ->orderBy('id')
            ->each(function (Company $company): void {
                foreach ([$company->name, $company->legal_name] as $name) {
                    $key = $this->normalize((string) $name);

                    if ($key !== '' && ! isset($this->companyIndex[$key])) {
                        $this->companyIndex[$key] = ['id' => (int) $company->getKey(), 'user_id' => $company->user_id];
                    }
                }
            });

        User::query()->clients()->select('id', 'name', 'erp_name')->orderBy('id')
            ->each(function (User $user): void {
                foreach ([$user->erp_name, $user->name] as $name) {
                    $key = $this->normalize((string) $name);

                    if ($key !== '' && ! isset($this->userIndex[$key])) {
                        $this->userIndex[$key] = (int) $user->getKey();
                    }
                }
            });
    }

    private function matchCompany(string $name): ?Company
    {
        $id = $this->lookup($this->companyIndex, $name)['id'] ?? null;

        return $id === null ? null : Company::query()->withoutGlobalScopes()->find($id);
    }

    private function matchUser(string $name): ?User
    {
        $id = $this->lookup($this->userIndex, $name);

        return $id === null ? null : User::query()->find($id);
    }

    /**
     * Точное совпадение нормализованного имени, затем вхождение — но только
     * для длинных ключей: «Гевея» входит в десяток названий.
     *
     * @template T
     *
     * @param  array<string, T>  $index
     * @return T|null
     */
    private function lookup(array $index, string $name): mixed
    {
        $key = $this->normalize($name);

        if ($key === '') {
            return null;
        }

        if (isset($index[$key])) {
            return $index[$key];
        }

        if (mb_strlen($key) < 12) {
            return null;
        }

        $candidates = [];

        foreach ($index as $indexed => $value) {
            if (mb_strlen($indexed) >= 12 && (str_contains($indexed, $key) || str_contains($key, $indexed))) {
                $candidates[] = $value;
            }
        }

        // Неоднозначность — не совпадение: лучше оставить менеджеру, чем привязать не туда.
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * «Свежий Ветер ООО г.Курск» и «ООО "Свежий ветер"» должны сойтись.
     */
    private function normalize(string $name): string
    {
        $value = Str::lower($name);
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[«»"„“”\'()]/u', ' ', $value) ?? $value;
        $value = preg_replace('/,.*$/u', '', $value) ?? $value; // город после запятой
        $value = preg_replace('/\bг\.\s*\S+/u', ' ', $value) ?? $value; // «г.Москва»
        $value = preg_replace('/\b(ооо|ип|ао|зао|пао|тоо|индивидуальный предприниматель|общество с ограниченной ответственностью)\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function matchManager(string $name): ?PersonalManager
    {
        $surname = Str::of($name)->trim()->explode(' ')->first();

        if (! is_string($surname) || $surname === '') {
            return null;
        }

        return PersonalManager::query()->where('name', 'like', $surname.'%')->orderBy('id')->first();
    }

    /**
     * @return array{0: ContractStatus, 1: string|null}
     */
    private function parseStatus(string $raw): array
    {
        $value = Str::lower($raw);

        return match (true) {
            $value === '' => [ContractStatus::DRAFT, null],
            str_contains($value, 'не отправлен') => [ContractStatus::DRAFT, null],
            str_contains($value, 'подписан'), $value === 'есть' => [ContractStatus::SIGNED, null],
            str_contains($value, 'отправлен') => [ContractStatus::SENT, null],
            str_contains($value, 'расторг') => [ContractStatus::TERMINATED, null],
            default => (function () use ($raw): array {
                $this->report['unknown_statuses'][] = $raw;

                return [ContractStatus::DRAFT, 'Статус из таблицы: '.$raw];
            })(),
        };
    }

    private function parsePayment(string $raw): ?ContractPaymentTerms
    {
        $value = Str::lower($raw);

        return match (true) {
            str_contains($value, 'предоплат') => ContractPaymentTerms::PREPAYMENT,
            str_contains($value, 'отсроч'), str_contains($value, 'острочк') => ContractPaymentTerms::DEFERRAL,
            str_contains($value, 'реализац') => ContractPaymentTerms::CONSIGNMENT,
            default => null,
        };
    }

    private function parseForm(string $raw): ?ContractForm
    {
        $value = Str::lower($raw);

        return match (true) {
            str_contains($value, 'эдо') => ContractForm::EDO,
            str_contains($value, 'скан') => ContractForm::SCAN,
            str_contains($value, 'оригинал') => ContractForm::ORIGINAL,
            default => null,
        };
    }

    private function cleanNumber(string $raw): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);

        // «№ 1 от 30.07.2024» — дата уходит в поле даты, в номере остаётся «№ 1».
        return trim(preg_replace('/\s+от\s+\d{2}\.\d{2}\.\d{4}$/u', '', $value) ?? $value);
    }

    private function dateFromNumber(string $raw): ?Carbon
    {
        if (preg_match('/от\s+(\d{2}\.\d{2}\.\d{4})/u', $raw, $m) === 1) {
            return Carbon::createFromFormat('d.m.Y', $m[1]);
        }

        return null;
    }

    private function parseDate(mixed $cell): ?Carbon
    {
        if ($cell === null || $cell === '') {
            return null;
        }

        if (is_numeric($cell)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $cell));
        }

        $value = trim((string) $cell);

        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd.m.Y', 'd.m.Y H:i:s'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function writeReport(): void
    {
        $this->report['unmatched_contractors'] = array_values(array_unique($this->report['unmatched_contractors']));
        $this->report['unmatched_partners'] = array_values(array_unique($this->report['unmatched_partners']));
        $this->report['unknown_statuses'] = array_values(array_unique($this->report['unknown_statuses']));

        $path = (string) ($this->option('report') ?: storage_path('app/contracts-import-report.json'));

        file_put_contents($path, json_encode($this->report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->line("Отчёт: {$path}");

        if ($this->report['unmatched_contractors'] !== []) {
            $this->warn('Контрагенты, не найденные в базе (заведены названием текстом):');
            foreach (Collection::make($this->report['unmatched_contractors'])->take(40) as $name) {
                $this->line('  · '.$name);
            }
        }
    }
}
