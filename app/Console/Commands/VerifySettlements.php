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
 * ## Ось дат (16.12.0)
 *
 * Инвариант точки режется по **периоду движения регистра** (`entry_date`) — тем же
 * полем, которым 1С набирает движения в точку. Строки без него остаются на прежней
 * оси `date`. Обе оси считаются рядом: пока историческая часть ленты поля не получила,
 * честный отчёт о включении — «сколько пар сходилось до и сколько сходится после»,
 * а не одно число. Разбор пар печатает `--checkpoint-detail=N`.
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
        {--checkpoint-detail=0 : Показать разбор N пар точки по обеим осям дат (date и entry_date)}
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

        $detail = (int) $this->option('checkpoint-detail');

        if ($detail > 0) {
            $this->renderCheckpointDetail(Carbon::parse((string) $this->option('checkpoint')), $detail);
        }

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
            ];
        }

        usort($rows, static fn (array $a, array $b): int => abs($b['delta_erp']) <=> abs($a['delta_erp']));

        return $rows;
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
            ['Контрагент', 'Орг.', 'Вал.', 'Регистр', '1С', 'Δ с 1С'],
            array_map(static fn (array $row): array => [
                mb_strimwidth((string) $row['company'], 0, 34, '…'),
                $row['organization_id'] ?? '—',
                $row['currency'],
                number_format($row['ledger'], 2, ',', ' '),
                number_format($row['erp'], 2, ',', ' '),
                number_format($row['delta_erp'], 2, ',', ' '),
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
        $this->line('company_id;company;organization_id;currency;ledger;erp;delta_erp');

        foreach ($rows as $row) {
            $this->line(implode(';', [
                $row['company_id'],
                str_replace(';', ',', (string) $row['company']),
                $row['organization_id'],
                $row['currency'],
                $row['ledger'],
                $row['erp'],
                $row['delta_erp'],
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
            'Лента до даты точки сходится с контрольной точкой' => $this->checkpointMismatchCount($checkpointDate),
        ];

        $coverage = $this->entryDateCoverage();

        $this->newLine();
        $this->info('Ось дат');
        $this->table(['Показатель', 'Значение'], [
            ['Движений с периодом регистра (entry_date)', $coverage['with']],
            ['Движений на фолбэке (date → document_date)', $coverage['without']],
        ]);

        if ($coverage['with'] === 0) {
            $this->line('Поле `entry_date` ещё не приезжает: лента целиком режется по прежней оси.');
        }

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
        return count(array_filter(
            $this->checkpointRows($asOf),
            static fn (array $row): bool => abs($row['delta_entry']) > self::EPSILON,
        ));
    }

    /**
     * Обе оси по каждой сверенной паре: старая (`date`) и новая (`entry_date`).
     *
     * Считаются рядом намеренно. Пока 1С не досылает историю, часть ленты остаётся
     * на прежней оси, и единственный честный отчёт о включении поля — «сколько пар
     * сходилось до и сколько сходится после», а не одно число.
     *
     * @return list<array<string, mixed>>
     */
    private function checkpointRows(Carbon $asOf): array
    {
        // Точки группируются по ОСИ СВЕРКИ — контрагент × организация × валюта,
        // а не берутся по одной. У контрагента, закреплённого за двумя партнёрами,
        // 1С законно снимает две точки (партнёр входит в её ключ), а лента на сайте
        // одна: партнёр в ось сверки не входит. Поштучное сравнение объявляло такую
        // пару расхождением дважды, хотя сумма точек сходится с лентой копейка
        // в копейку — случай Войдакова: −13 647,75 и −4 955,00 против ленты
        // −18 602,75 (топик №10, 28.09.2026).
        $groups = SettlementCheckpoint::query()->verified()->asOf($asOf)->get()
            ->filter(static fn (SettlementCheckpoint $checkpoint): bool => $checkpoint->company_id !== null)
            ->groupBy(static fn (SettlementCheckpoint $checkpoint): string => implode('|', [
                $checkpoint->company_id,
                $checkpoint->organization_id,
                $checkpoint->currency_code,
            ]));

        $rows = [];

        foreach ($groups as $group) {
            $checkpoint = $group->first();

            $base = fn () => SettlementEntry::query()
                ->facts()
                ->where('type', '!=', SettlementEntry::TYPE_OPENING_BALANCE)
                ->forReconciliation($checkpoint->company_id, $checkpoint->organization_id, $checkpoint->currency_code);

            // Прежняя ось: дата хозяйственной операции, строго раньше даты точки.
            $byDate = (float) $base()->whereDate('date', '<', $asOf->toDateString())->sum('amount');

            // Новая ось (16.12.0): период движения регистра, фолбэк на прежнюю
            // для строк, приехавших до включения поля.
            $byEntry = (float) $base()->beforeCheckpoint($asOf)->sum('amount');

            $amount = (float) $group->sum(static fn (SettlementCheckpoint $row): float => (float) $row->amount);

            $rows[] = [
                'company_id' => $checkpoint->company_id,
                'organization_id' => $checkpoint->organization_id,
                'currency' => $checkpoint->currency_code,
                'as_of_date' => $asOf->toDateString(),
                'checkpoint' => round($amount, 2),
                'checkpoint_parts' => $group->count(),
                'ledger_date' => round($byDate, 2),
                'ledger_entry' => round($byEntry, 2),
                'delta_date' => round($byDate - $amount, 2),
                'delta_entry' => round($byEntry - $amount, 2),
            ];
        }

        return $rows;
    }

    /**
     * Покрытие новой оси: сколько фактических движений несут период движения
     * регистра, а сколько живёт на фолбэке. Ровно эти числа 1С ждёт в отчёте
     * о включении поля.
     *
     * @return array{with: int, without: int}
     */
    private function entryDateCoverage(): array
    {
        $with = SettlementEntry::query()->facts()->whereNotNull('entry_date')->count();
        $without = SettlementEntry::query()->facts()->whereNull('entry_date')->count();

        return ['with' => $with, 'without' => $without];
    }

    /**
     * Разбор пар точки по обеим осям — доказательство для отчёта в шину:
     * видно, какие пары сходились до включения поля и какие сходятся после.
     */
    private function renderCheckpointDetail(Carbon $asOf, int $limit): void
    {
        $rows = $this->checkpointRows($asOf);

        if ($rows === []) {
            $this->warn(sprintf('Сверенных контрольных точек на %s нет — разбор по осям пуст.', $asOf->toDateString()));

            return;
        }

        // Сначала пары, где оси дали разный ответ: именно они и есть предмет спора.
        usort($rows, static fn (array $a, array $b): int => abs($b['ledger_entry'] - $b['ledger_date'])
            <=> abs($a['ledger_entry'] - $a['ledger_date']));

        $this->newLine();
        $this->info(sprintf('Разбор точки %s по осям дат (первые %d пар)', $asOf->toDateString(), $limit));
        $this->table(
            ['Контрагент', 'Орг.', 'Вал.', 'Точка', 'Лента по date', 'Лента по entry_date', 'Δ до', 'Δ после'],
            array_map(static fn (array $row): array => [
                $row['company_id'],
                $row['organization_id'] ?? '—',
                $row['currency'],
                number_format($row['checkpoint'], 2, ',', ' ').($row['checkpoint_parts'] > 1 ? sprintf(' (%d точки)', $row['checkpoint_parts']) : ''),
                number_format($row['ledger_date'], 2, ',', ' '),
                number_format($row['ledger_entry'], 2, ',', ' '),
                number_format($row['delta_date'], 2, ',', ' '),
                number_format($row['delta_entry'], 2, ',', ' '),
            ], array_slice($rows, 0, $limit)),
        );
    }
}
