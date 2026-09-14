<?php

namespace App\Console\Commands\Crm;

use App\Models\CrmCompetitorPurchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Импорт инсайда по закупкам у конкурента: CSV «партнёр; сумма; штук; документов;
 * последняя; первая; контрагенты» → сопоставление с нашими партнёрами по
 * наименованию → таблица crm_competitor_purchases.
 *
 * Сопоставление только по нормализованному имени: GUID в базах 1С разные.
 * `erp_name` приоритетнее личного `name` (рабочее наименование от 1С точнее,
 * чем то, что клиент ввёл сам). Несопоставленные строки перечисляются в отчёте —
 * это кандидаты на привлечение, которых у нас вообще нет.
 */
class ImportCompetitorPurchases extends Command
{
    protected $signature = 'crm:import-competitor-purchases
        {file : CSV с разделителем «;» (docs/sales/analytics/konkurent-partnery-12m-chistye.csv)}
        {--source=andrey : Ключ источника}
        {--from= : Начало окна выгрузки Y-m-d}
        {--to= : Конец окна выгрузки Y-m-d}
        {--unmatched=20 : Сколько несопоставленных показать в отчёте}';

    protected $description = 'Импортировать закупки партнёров у конкурента из инсайдерской выгрузки, сопоставив по наименованию';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Файл не читается: {$path}");

            return self::FAILURE;
        }

        $source = (string) $this->option('source');
        $map = $this->nameMap();
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, 0, ';');

        if ($header === false || count($header) < 6) {
            $this->error('Ожидается CSV с колонками partner;sum;qty;docs;last_date;first_date;contractors');

            return self::FAILURE;
        }

        $rows = [];
        $unmatched = [];
        $from = null;
        $to = null;

        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            [$partner, $sum, , $docs, $last, $first] = array_pad($row, 7, '');
            $contractors = (string) ($row[6] ?? '');
            $hit = $map[$this->normalize($partner)] ?? null;

            if ($hit === null) {
                foreach (array_filter(explode('|', $contractors)) as $contractor) {
                    $hit = $map[$this->normalize($contractor)] ?? null;

                    if ($hit !== null) {
                        break;
                    }
                }
            }

            $from = $first !== '' && ($from === null || $first < $from) ? $first : $from;
            $to = $last !== '' && ($to === null || $last > $to) ? $last : $to;

            if ($hit === null) {
                $unmatched[] = ['partner' => $partner, 'amount' => (float) $sum, 'last' => $last];

                continue;
            }

            // Один наш партнёр может совпасть с несколькими строками (юрлицо + ИП): суммируем.
            $existing = $rows[$hit] ?? null;
            $rows[$hit] = [
                'user_id' => $hit,
                'source' => $source,
                'competitor_partner' => $existing === null ? $partner : $existing['competitor_partner'].' | '.$partner,
                'amount' => ($existing['amount'] ?? 0) + (float) $sum,
                'documents' => ($existing['documents'] ?? 0) + (int) $docs,
                'first_purchase_on' => $first !== '' ? min($first, $existing['first_purchase_on'] ?? $first) : ($existing['first_purchase_on'] ?? null),
                'last_purchase_on' => $last !== '' ? max($last, $existing['last_purchase_on'] ?? $last) : ($existing['last_purchase_on'] ?? null),
            ];
        }
        fclose($fh);

        $periodFrom = (string) ($this->option('from') ?: $from ?: CarbonImmutable::today()->subYear()->toDateString());
        $periodTo = (string) ($this->option('to') ?: $to ?: CarbonImmutable::today()->toDateString());
        $now = now();

        DB::transaction(function () use ($rows, $source, $periodFrom, $periodTo, $now): void {
            CrmCompetitorPurchase::query()->where('source', $source)->delete();

            foreach ($rows as $row) {
                CrmCompetitorPurchase::query()->create($row + [
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'imported_at' => $now,
                ]);
            }
        });

        $matchedAmount = array_sum(array_column($rows, 'amount'));
        $unmatchedAmount = array_sum(array_column($unmatched, 'amount'));

        $this->info(sprintf(
            'Окно %s — %s. Сопоставлено партнёров: %d на %s ₽; не сопоставлено: %d на %s ₽.',
            $periodFrom, $periodTo, count($rows), number_format($matchedAmount, 0, ',', ' '),
            count($unmatched), number_format($unmatchedAmount, 0, ',', ' '),
        ));

        usort($unmatched, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
        $limit = max(0, (int) $this->option('unmatched'));

        if ($limit > 0 && $unmatched !== []) {
            $this->line('Крупнейшие несопоставленные (у нас такого партнёра нет):');
            $this->table(['Партнёр у конкурента', 'Сумма, ₽', 'Последняя'], array_map(
                fn (array $u): array => [$u['partner'], number_format($u['amount'], 0, ',', ' '), $u['last']],
                array_slice($unmatched, 0, $limit),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Нормализованное имя → id партнёра. erp_name имеет приоритет над name.
     *
     * @return array<string, int>
     */
    private function nameMap(): array
    {
        $map = [];
        $priority = [];

        foreach (User::query()->clients()->get(['id', 'name', 'erp_name']) as $user) {
            foreach (['erp' => $user->erp_name, 'own' => $user->name] as $src => $candidate) {
                if ($candidate === null || $candidate === '') {
                    continue;
                }

                $key = $this->normalize((string) $candidate);

                if ($key === '' || mb_strlen($key) < 4) {
                    continue;
                }

                if (! isset($map[$key]) || ($src === 'erp' && $priority[$key] === 'own')) {
                    $map[$key] = (int) $user->id;
                    $priority[$key] = $src;
                }
            }
        }

        return $map;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);
        $value = (string) preg_replace('/\(\d+\)\s*$/u', '', $value);
        $value = (string) preg_replace('/\b(общество с ограниченной ответственностью|индивидуальный предприниматель|торговый дом|ооо|ип|зао|оао|пао|нао|ао|тд|гк)\b/u', ' ', $value);

        return (string) preg_replace('/[^a-zа-я0-9]+/u', '', $value);
    }
}
