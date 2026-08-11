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
 * Три источника, из которых первые два обязаны совпасть:
 *
 *  1. **Регистр** — SUM(settlement_entries.amount) по фактическим движениям.
 *  2. **Мнение 1С** — contractor_organization_balances из balance.updated.
 *  3. **Старая модель** — непогашенный остаток по строкам графика реализаций.
 *
 * Третий источник расходиться обязан: ради этого расхождения эпик и затевался.
 * Если расходятся первые два — виновата интеграция, а не старая модель.
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
        {--checkpoint=2026-07-01 : Дата контрольной точки для инварианта}';

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

        $mismatched = array_values(array_filter(
            $rows,
            static fn (array $row): bool => abs($row['delta_erp']) > $threshold,
        ));

        $visible = $this->option('only-mismatch') ? $mismatched : $rows;

        $this->option('format') === 'csv'
            ? $this->renderCsv($visible)
            : $this->renderTable($visible);

        $this->renderSummary($rows, $mismatched, $threshold);
        $failedInvariants = $this->renderInvariants();

        // Ненулевой код — чтобы гейт можно было поставить в CI, а не читать глазами.
        return $mismatched === [] && $failedInvariants === 0 ? self::SUCCESS : self::FAILURE;
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
     */
    private function renderSummary(array $rows, array $mismatched, float $threshold): void
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
        ]);
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
            'Нет движений раньше начала ленты' => SettlementEntry::query()
                ->where('type', '!=', SettlementEntry::TYPE_OPENING_BALANCE)
                ->whereDate('date', '<', '2026-01-01')->count(),
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

        return SettlementEntry::query()->facts()->where(function ($query) use ($negativeOnly, $positiveOnly) {
            $query->where(fn ($inner) => $inner->whereIn('type', $negativeOnly)->where('amount', '>', self::EPSILON))
                ->orWhere(fn ($inner) => $inner->whereIn('type', $positiveOnly)->where('amount', '<', -self::EPSILON));
        })->count();
    }

    /**
     * «Сальдо на 01.01 + движения до даты закрытия» обязано сойтись со сверенной
     * контрольной точкой. Расхождение говорит, что история первого полугодия
     * недостоверна, — и это решение принимается осознанно, а не по умолчанию.
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
