<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ContractorOrganizationBalance;
use App\Models\SettlementCheckpoint;
use App\Models\SettlementEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Сверка регистра взаиморасчётов с мнением 1С (v16.0.0, карточка fin-06).
 *
 * Приёмочный гейт волны 3: пока команда не показывает ноль расхождений,
 * чтение денег на регистр не переключается. Это единственный объективный критерий,
 * что новая модель лучше старой, а не просто другая.
 *
 * Три источника:
 *
 *  1. **Регистр** — SUM(settlement_entries.amount) по фактическим движениям.
 *  2. **Мнение 1С** — contractor_organization_balances из balance.updated.
 *  3. **Старая модель** — непогашенный остаток по строкам графика реализаций.
 *
 * Третий источник расходиться обязан: ради этого расхождения эпик и затевался.
 *
 * ## Что считается провалом
 *
 * Код возврата определяют **инварианты**, а не расхождение с `balance.updated`.
 *
 * Так сделано после круга 8 (14.08.2026): 1С подтвердила, что канал балансов
 * сломан и правка не запланирована, а лента строится прямо из регистра и в спорных
 * случаях права именно она. Живой пример — ЛАМУР, Пономарева, Земсков: 1С прислала
 * начальное сальдо, оно совпадает с её же регистром, а `balance.updated` по этим
 * парам отдаёт ноль. Держать гейт на источнике, который сама 1С считает
 * недостоверным, значит ждать чужую задачу без срока.
 *
 * Честный сигнал готовности — контрольная точка: «сальдо 01.01 + движения = checkpoint».
 * Обе стороны равенства приходят из регистра, поэтому расхождение означает дырку
 * в истории, а не спор двух каналов.
 *
 * Расхождение с балансами по-прежнему считается и печатается — оно полезно как
 * ранний признак, — но выход остаётся нулевым. `--strict-balances` возвращает
 * прежнее поведение, когда канал балансов починят.
 *
 * Команда только читает. Запускать на проде безопасно.
 */
class VerifySettlements extends Command
{
    protected $signature = 'settlements:verify
        {--client= : ID партнёра — сверить одного клиента}
        {--threshold=1.00 : Порог расхождения в рублях}
        {--format=table : table или csv}
        {--only-mismatch : Показывать только расхождения}
        {--checkpoint=2026-08-01 : Дата контрольной точки-эталона (01.08.2026 — итог по 31.07, 150 пар)}
        {--strict-balances : Считать провалом и расхождение с balance.updated}';

    protected $description = 'Сверка регистра взаиморасчётов с балансами из 1С и со старой моделью';

    /**
     * Погрешность сравнения денег — та же, что в моделях.
     */
    private const EPSILON = 0.01;

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');

        $rows = $this->buildRows();

        if ($rows === []) {
            $this->warn('Сверять нечего: ни движений, ни балансов не найдено.');

            return self::SUCCESS;
        }

        $orphans = array_values(array_filter($rows, static fn (array $r): bool => $r['orphan_balance']));

        $mismatched = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! $row['orphan_balance'] && abs($row['delta_erp']) > $threshold,
        ));

        $visible = $this->option('only-mismatch') ? $mismatched : $rows;

        $this->option('format') === 'csv'
            ? $this->renderCsv($visible)
            : $this->renderTable($visible);

        $this->renderSummary($rows, $mismatched, $orphans, $threshold);
        $failedInvariants = $this->renderInvariants();

        // Ненулевой код — чтобы гейт можно было поставить в CI, а не читать глазами.
        // Балансы в приговор не входят: см. докблок класса.
        $balancesFail = $this->option('strict-balances') && $mismatched !== [];

        return $failedInvariants === 0 && ! $balancesFail ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Сводка по оси сверки: контрагент × организация × валюта.
     *
     * @return list<array<string, mixed>>
     */
    private function buildRows(): array
    {
        $clientId = $this->option('client');

        $ledger = SettlementEntry::query()
            ->facts()
            ->when($clientId, fn ($query) => $query->where('user_id', $clientId))
            ->selectRaw('company_id, organization_id, currency_code, SUM(amount) AS total')
            ->groupBy('company_id', 'organization_id', 'currency_code')
            ->get()
            ->keyBy(fn ($row) => $this->key($row->company_id, $row->organization_id, $row->currency_code));

        $balances = ContractorOrganizationBalance::query()
            ->when($clientId, fn ($query) => $query->where('user_id', $clientId))
            ->get()
            // Балансы приходят только в рублях — валюта в ключ подставляется явно.
            ->keyBy(fn ($row) => $this->key($row->company_id, $row->organization_id, 'RUB'));

        $legacy = $this->legacyDebts($clientId);

        $names = Company::withoutGlobalScopes()
            ->whereIn('id', array_filter(array_merge(
                $ledger->pluck('company_id')->all(),
                $balances->pluck('company_id')->all(),
            )))
            ->pluck('name', 'id');

        $rows = [];

        foreach (array_unique(array_merge($ledger->keys()->all(), $balances->keys()->all())) as $key) {
            [$companyId, $organizationId, $currency] = explode('|', (string) $key);

            $ledgerTotal = (float) ($ledger[$key]->total ?? 0.0);
            $erpTotal = (float) ($balances[$key]->current_balance ?? 0.0);
            $legacyTotal = (float) ($legacy[$key] ?? 0.0);

            // Клиент без движений и с нулевым балансом — не расхождение, а пустая строка.
            if (abs($ledgerTotal) <= self::EPSILON && abs($erpTotal) <= self::EPSILON) {
                continue;
            }

            $rows[] = [
                // Баланс есть, движений нет вовсе — контрагент выведен из обмена,
                // а баланс продолжает приходить: канал `balance.updated` список
                // исключений не смотрит (подтверждено 1С 13.08.2026, обещали
                // починить). Это не расхождение данных, а рассинхрон двух каналов
                // на их стороне, и держать его в общем счётчике значит месяцами
                // смотреть на красное там, где всё правильно.
                'orphan_balance' => abs($ledgerTotal) <= self::EPSILON,
                'company_id' => $companyId === '' ? null : (int) $companyId,
                'company' => $names[(int) $companyId] ?? '(контрагент не сопоставлен)',
                'organization_id' => $organizationId === '' ? null : (int) $organizationId,
                'currency' => $currency,
                'ledger' => round($ledgerTotal, 2),
                'erp' => round($erpTotal, 2),
                'delta_erp' => round($ledgerTotal - $erpTotal, 2),
                'legacy' => round($legacyTotal, 2),
                'delta_legacy' => round($ledgerTotal + $legacyTotal, 2),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => abs($b['delta_erp']) <=> abs($a['delta_erp']));

        return $rows;
    }

    /**
     * Долг по старой модели: непогашенный остаток строк графика реализаций.
     *
     * Именно это число сегодня видят CRM и кабинет, и именно оно расходится
     * с 1С — сумма неоплаченных документов больше долга в разы, потому что
     * 1С гасит его ещё авансами по заказам.
     *
     * @return array<string, float>
     */
    private function legacyDebts(?string $clientId): array
    {
        return DB::table('shipment_payment_schedules AS s')
            ->join('shipments AS d', 'd.id', '=', 's.shipment_id')
            ->whereNull('d.deleted_at')
            ->when($clientId, fn ($query) => $query->where('d.user_id', $clientId))
            ->selectRaw('d.company_id AS company_id, d.organization_id AS organization_id, d.currency_code AS currency_code')
            // GREATEST — функция MySQL; в SQLite ту же роль играет двухаргументный MAX.
            ->selectRaw(sprintf(
                'SUM(%s(s.amount - s.paid_amount - s.prepaid_amount, 0)) AS total',
                DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST',
            ))
            ->groupBy('d.company_id', 'd.organization_id', 'd.currency_code')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->key($row->company_id, $row->organization_id, $row->currency_code ?? 'RUB') => (float) $row->total,
            ])
            ->all();
    }

    private function key(?int $companyId, ?int $organizationId, ?string $currency): string
    {
        return implode('|', [$companyId, $organizationId, $currency ?? 'RUB']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderTable(array $rows): void
    {
        if ($rows === []) {
            $this->info('Расхождений выше порога нет.');

            return;
        }

        $this->table(
            ['Контрагент', 'Орг.', 'Вал.', 'Регистр', '1С', 'Δ с 1С', 'Старая модель', 'Δ со старой'],
            array_map(static fn (array $row): array => [
                mb_strimwidth((string) $row['company'], 0, 34, '…'),
                $row['organization_id'] ?? '—',
                $row['currency'],
                number_format($row['ledger'], 2, ',', ' '),
                number_format($row['erp'], 2, ',', ' '),
                number_format($row['delta_erp'], 2, ',', ' '),
                number_format($row['legacy'], 2, ',', ' '),
                number_format($row['delta_legacy'], 2, ',', ' '),
            ], array_slice($rows, 0, 100)),
        );

        if (count($rows) > 100) {
            $this->line(sprintf('… и ещё %d строк. Для полного разбора: --format=csv', count($rows) - 100));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderCsv(array $rows): void
    {
        $this->line('company_id;company;organization_id;currency;ledger;erp;delta_erp;legacy;delta_legacy');

        foreach ($rows as $row) {
            $this->line(implode(';', [
                $row['company_id'],
                str_replace(';', ',', (string) $row['company']),
                $row['organization_id'],
                $row['currency'],
                $row['ledger'],
                $row['erp'],
                $row['delta_erp'],
                $row['legacy'],
                $row['delta_legacy'],
            ]));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $mismatched
     * @param  list<array<string, mixed>>  $orphans
     */
    private function renderSummary(array $rows, array $mismatched, array $orphans, float $threshold): void
    {
        $withinThreshold = array_values(array_filter(
            $rows,
            static fn (array $row): bool => abs($row['delta_erp']) > self::EPSILON && abs($row['delta_erp']) <= $threshold,
        ));

        $this->newLine();
        $this->info('Сводка');
        $this->table(['Показатель', 'Значение'], [
            ['Сверено пар «контрагент × организация × валюта»', count($rows)],
            ['Сошлось с 1С', count($rows) - count($mismatched)],
            ['Расходится с 1С выше порога', count($mismatched)],
            // Копейки по тысяче контрагентов дают заметную сумму — показываем отдельно,
            // а не прячем под порогом.
            ['В пределах порога (но не ноль)', count($withinThreshold)],
            ['Суммарное расхождение с 1С', number_format(
                array_sum(array_map(static fn (array $row): float => abs($row['delta_erp']), $mismatched)),
                2, ',', ' ',
            )],
            ['— балансы без движений (вне обмена)', sprintf(
                '%d пар на %s',
                count($orphans),
                number_format(array_sum(array_map(static fn (array $r): float => abs($r['delta_erp']), $orphans)), 2, ',', ' '),
            )],
        ]);

        if ($orphans !== []) {
            $this->line('Балансы без движений в счётчик расхождений не входят: это контрагенты,');
            $this->line('выведенные из обмена, по которым 1С продолжает слать `balance.updated`.');
        }

        if ($mismatched !== [] && ! $this->option('strict-balances')) {
            $this->newLine();
            $this->comment('Расхождение с `balance.updated` показано справочно и на код возврата не влияет:');
            $this->comment('1С признала канал балансов недостоверным. Готовность определяют инварианты ниже.');
        }
    }

    /**
     * Инварианты, которые схема поймать не может.
     *
     * @return int Число нарушенных инвариантов
     */
    private function renderInvariants(): int
    {
        $checkpointDate = Carbon::parse((string) $this->option('checkpoint'));

        $checks = [
            'Знак движения соответствует типу' => $this->wrongSignCount(),
            'У фактических движений нет погашенной части' => SettlementEntry::query()
                ->facts()->whereRaw('settled_amount > '.self::EPSILON)->count(),
            // Инварианта «нет движений раньше начала ленты» здесь больше нет:
            // он исходил из того, что лента начинается 01.01.2026, а она начинается
            // раньше — 1С отдаёт историю целиком, 772 движения датированы 2025 годом.
            // Проверка объявляла нарушением совершенно законные строки (v16.3.0).
            'Начальное сальдо не попало в ленту' => SettlementEntry::query()
                ->where('type', SettlementEntry::TYPE_OPENING_BALANCE)->count(),
            'Разнесённая оплата заказа помечена производной' => SettlementEntry::query()
                ->plans()->where('document_kind', 'order')
                ->whereRaw('settled_amount > '.self::EPSILON)
                ->where('is_settled_derived', false)->count(),
            'Сальдо 01.01 + движения сходятся с контрольной точкой' => $this->checkpointMismatchCount($checkpointDate),
        ];

        $this->newLine();
        $this->info('Инварианты');
        $this->table(
            ['Проверка', 'Нарушений'],
            array_map(
                static fn (string $name, int $count): array => [$name, $count === 0 ? '—' : $count],
                array_keys($checks),
                array_values($checks),
            ),
        );

        return count(array_filter($checks));
    }

    /**
     * Перепутанный знак — единственная ошибка, которую валидация схемы пропускает:
     * суммы правильные, а баланс инвертирован у всей базы.
     */
    private function wrongSignCount(): int
    {
        $negativeOnly = [
            SettlementEntry::TYPE_SHIPMENT,
            SettlementEntry::TYPE_PAYMENT_OUT,
            SettlementEntry::TYPE_COMMISSION_SALE,
        ];

        $positiveOnly = [
            SettlementEntry::TYPE_PAYMENT_IN,
            SettlementEntry::TYPE_GOODS_RETURN,
        ];

        // Проверка по документу, а не построчно. Внутри одного документа
        // законно встречается строка обратного знака: в отчёте комиссионера
        // на −5 196 приезжала строка +31,32 — комиссия или округление. Строгая
        // построчная проверка объявляла это инверсией знака и уводила разбор
        // в ложный след, пока настоящих нарушений не было вовсе.
        // Движение без документа-регистратора проверяется само по себе: иначе
        // строки, которым не с чем группироваться, выпали бы из проверки вовсе.
        $key = "COALESCE(document_uuid, CONCAT('row-', id))";

        // get()->count() здесь обязателен: агрегат с HAVING, и count() на самом
        // запросе вернул бы число строк внутри групп, а не число документов.
        // @phpstan-ignore larastan.noUnnecessaryCollectionCall
        return DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->whereIn('type', array_merge($negativeOnly, $positiveOnly))
            ->groupBy(DB::raw($key), 'type')
            ->selectRaw('type, SUM(amount) as total')
            ->havingRaw(sprintf(
                '(type IN (%s) AND SUM(amount) > %s) OR (type IN (%s) AND SUM(amount) < -%s)',
                "'".implode("','", $negativeOnly)."'",
                self::EPSILON,
                "'".implode("','", $positiveOnly)."'",
                self::EPSILON,
            ))
            ->get()
            ->count();
    }

    /**
     * Сумма ленты до даты точки обязана сойтись со сверенной контрольной точкой.
     *
     * Обе стороны равенства приходят из регистра 1С, поэтому расхождение означает
     * дырку в истории, а не спор двух каналов, — в отличие от сравнения с
     * `balance.updated`, которое 1С сама считает недостоверным.
     *
     * Строки `opening_balance` в расчёт не входят: лента содержит историю целиком,
     * и сальдо её дублирует (v16.3.0). После чистки прода таких строк нет вовсе,
     * но исключение оставлено — на случай, если 1С пришлёт их снова.
     */
    private function checkpointMismatchCount(Carbon $asOf): int
    {
        $checkpoints = SettlementCheckpoint::query()->verified()->asOf($asOf)->get();

        $mismatched = 0;

        foreach ($checkpoints as $checkpoint) {
            if ($checkpoint->company_id === null) {
                continue;
            }

            $ledger = (float) SettlementEntry::query()
                ->facts()
                ->where('type', '!=', SettlementEntry::TYPE_OPENING_BALANCE)
                ->forReconciliation($checkpoint->company_id, $checkpoint->organization_id, $checkpoint->currency_code)
                // Контрольная точка — состояние НА начало дня, поэтому строго раньше.
                ->whereDate('date', '<', $asOf->toDateString())
                ->sum('amount');

            if (abs($ledger - (float) $checkpoint->amount) > self::EPSILON) {
                $mismatched++;
            }
        }

        return $mismatched;
    }
}
