<?php

namespace App\Console\Commands;

use App\Models\Agreement;
use App\Models\ContractorOrganizationBalance;
use App\Models\SettlementCheckpoint;
use App\Models\SettlementDocument;
use App\Models\SettlementEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Сводка по наполнению регистра взаиморасчётов (v16.0.0, карточка fin-05).
 *
 * Инструмент наблюдения за первичной выгрузкой: сколько приехало, чего не хватает,
 * что осталось несопоставленным. В отличие от `settlements:verify` ничего не сверяет
 * и вердикта не выносит — только показывает состояние.
 *
 * Смотреть в первую очередь на несопоставленные связи: движение с пустым `company_id`
 * сохранится и денег не потеряет, но в акт сверки клиента не попадёт.
 */
class SettlementStats extends Command
{
    protected $signature = 'settlements:stats';

    protected $description = 'Состояние регистра взаиморасчётов: объёмы, пробелы, несопоставленные связи';

    public function handle(): int
    {
        $this->renderVolumes();
        $this->renderByType();
        $this->renderGaps();
        $this->renderCoverage();

        return self::SUCCESS;
    }

    private function renderVolumes(): void
    {
        $facts = SettlementEntry::query()->facts();
        $plans = SettlementEntry::query()->plans();

        $this->info('Объёмы');
        $this->table(['Показатель', 'Значение'], [
            ['Соглашений', Agreement::query()->count()],
            ['Документов-регистраторов', SettlementDocument::query()->count()],
            ['— из них с отменённым проведением', SettlementDocument::query()->where('is_reverted', true)->count()],
            ['Фактических движений', (clone $facts)->count()],
            ['Плановых строк графика', (clone $plans)->count()],
            ['Непогашенных плановых строк', SettlementEntry::query()->outstanding()->count()],
            ['Просроченных плановых строк', SettlementEntry::query()->overdue()->count()],
            ['Контрольных точек сальдо', SettlementCheckpoint::query()->count()],
            ['— сверенных', SettlementCheckpoint::query()->verified()->count()],
        ]);
    }

    private function renderByType(): void
    {
        // Через query builder, а не Eloquent: строки агрегата — не модели,
        // и колонки COUNT/SUM на SettlementEntry не существуют.
        $rows = DB::table('settlement_entries')
            // Псевдоним не `lines`: это зарезервированное слово MySQL, и запрос
            // падает с syntax error — на SQLite он при этом проходит.
            ->selectRaw('type, COUNT(*) AS entry_count, SUM(amount) AS total')
            ->groupBy('type')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->info('По типам движений');
        $this->table(
            ['Тип', 'Название', 'Строк', 'Сумма'],
            $rows->map(static fn (object $row): array => [
                $row->type,
                SettlementEntry::TYPE_LABELS[$row->type] ?? '(неизвестный тип)',
                $row->entry_count,
                number_format((float) $row->total, 2, ',', ' '),
            ])->all(),
        );
    }

    /**
     * Пробелы, из-за которых движение сохранится, но в отчёт клиента не попадёт.
     */
    private function renderGaps(): void
    {
        $total = max(1, SettlementEntry::query()->count());

        $gaps = [
            'Движений без контрагента' => SettlementEntry::query()->whereNull('company_id')->count(),
            'Движений без нашей организации' => SettlementEntry::query()->whereNull('organization_id')->count(),
            'Движений без партнёра' => SettlementEntry::query()->whereNull('user_id')->count(),
            'Движений без соглашения' => SettlementEntry::query()->whereNull('agreement_id')->count(),
            'Движений без документа на сайте' => SettlementEntry::query()->whereNull('document_id')->count(),
            'Соглашений без движений' => Agreement::query()->whereDoesntHave('settlementEntries')->count(),
        ];

        $this->newLine();
        $this->info('Пробелы');
        $this->table(
            ['Показатель', 'Строк', 'Доля'],
            array_map(
                static fn (string $name, int $count): array => [
                    $name,
                    $count,
                    $count === 0 ? '—' : round($count * 100 / $total, 1).' %',
                ],
                array_keys($gaps),
                array_values($gaps),
            ),
        );

        $this->line('Движение без соглашения или без документа — норма: соглашение заполнено');
        $this->line('не всегда, а отчёт комиссионера на сайт не приезжает вовсе. Без контрагента');
        $this->line('— уже нет: такая строка в акт сверки клиента не попадёт.');
    }

    /**
     * Контрагенты, у которых баланс есть, а начального сальдо нет: их лента
     * начинается с нуля, и весь долг до 2026 года потерян.
     */
    private function renderCoverage(): void
    {
        $withBalance = ContractorOrganizationBalance::query()
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id');

        if ($withBalance->isEmpty()) {
            return;
        }

        $withOpening = SettlementEntry::query()
            ->where('type', SettlementEntry::TYPE_OPENING_BALANCE)
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id');

        $missing = $withBalance->diff($withOpening);

        $this->newLine();
        $this->info('Покрытие начальным сальдо');
        $this->table(['Показатель', 'Значение'], [
            ['Контрагентов с балансом', $withBalance->count()],
            ['— из них с начальным сальдо', $withBalance->count() - $missing->count()],
            ['— без начального сальдо', $missing->count()],
        ]);

        if ($missing->isNotEmpty()) {
            $this->warn(sprintf(
                'У %d контрагентов лента начинается с нуля: долг до даты отсечки потерян.',
                $missing->count(),
            ));
        }
    }
}
