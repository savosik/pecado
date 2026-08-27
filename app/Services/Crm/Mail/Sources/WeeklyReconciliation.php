<?php

namespace App\Services\Crm\Mail\Sources;

use App\Enums\PrintedDocumentType;
use App\Models\PrintedDocument;
use App\Models\SettlementEntry;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Периодические поводы вокруг сверки.
 *
 * 1С выкладывает акты сверки каждый день. Подписка на «опубликован документ»
 * дала бы клиенту ежедневное письмо, которое он перестанет замечать через
 * неделю, — а сами акты в кабинете нужны всегда.
 *
 * Поэтому периодичность выражена **отдельными событиями**, а не настройкой
 * «раз в неделю» у существующего повода: иначе в матрицу вернулись бы
 * расписания и условия, то есть заново собрался бы движок правил.
 *
 * Клиент выбирает сам: снять акты из ежедневных документов и подписаться
 * на понедельничную сводку — тогда счета приходят сразу, а сверка раз в неделю.
 */
class WeeklyReconciliation
{
    public function __construct(private readonly MailStream $stream) {}

    /**
     * @return array{summaries: int, debtors: int}
     */
    public function run(bool $dryRun = false): array
    {
        return [
            'summaries' => $this->weeklySummaries($dryRun),
            'debtors' => $this->debtorReminders($dryRun),
        ];
    }

    /**
     * Сводка актов за неделю — тем, у кого они за эту неделю появились.
     *
     * Клиенту без новых актов письмо не уходит: «за неделю ничего» —
     * не новость, а повод перестать читать рассылку.
     */
    private function weeklySummaries(bool $dryRun): int
    {
        $since = now()->subWeek();

        $acts = PrintedDocument::query()
            ->where('type', PrintedDocumentType::RECONCILIATION_ACT->value)
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->get(['user_id', 'company_id', 'number', 'date'])
            ->groupBy('user_id');

        $sent = 0;

        foreach ($acts as $clientId => $rows) {
            if ($dryRun) {
                $sent++;

                continue;
            }

            $this->stream->captureQuietly(new Occasion(
                key: 'documents.reconciliation_weekly',
                clientUserId: (int) $clientId,
                data: [
                    'documents_count' => $rows->count(),
                    'period_from' => $since->toDateString(),
                    'period_to' => now()->toDateString(),
                ],
                view: [
                    'title' => 'Акты сверки за неделю',
                    'body' => $this->summaryBody($rows),
                    'url' => url(route('cabinet.documents.index', [], false)),
                    'rows' => $rows->map(fn ($row): array => [
                        'kind' => 'note',
                        'text' => 'Акт сверки № '.$row->number.' от '.($row->date?->format('d.m.Y') ?? '—'),
                    ])->values()->all(),
                ],
            ));

            $sent++;
        }

        return $sent;
    }

    /**
     * Напоминание о сверке тем, у кого есть непогашенный долг.
     *
     * Отдельное событие, а не условие у сводки: «сверка при долге» — другой
     * разговор с клиентом, и подписываться на него он решает отдельно.
     */
    private function debtorReminders(bool $dryRun): int
    {
        $debtors = SettlementEntry::query()
            ->overdue(Carbon::today())
            ->whereNotNull('user_id')
            ->get(['user_id', 'nature', 'document_kind', 'amount', 'settled_amount', 'amount_rub', 'currency_code', 'date'])
            ->groupBy('user_id');

        $sent = 0;

        foreach ($debtors as $clientId => $lines) {
            $amount = round($lines->sum(fn ($line): float => (float) $line->unsettled_amount), 2);

            if ($amount <= 0) {
                continue;
            }

            if ($dryRun) {
                $sent++;

                continue;
            }

            $this->stream->captureQuietly(new Occasion(
                key: 'finance.reconciliation_due',
                clientUserId: (int) $clientId,
                data: [
                    'overdue_amount' => $amount,
                    'positions_count' => $lines->count(),
                ],
                view: [
                    'title' => 'Сверка расчётов',
                    'body' => 'По нашим данным за вами числится непогашенная задолженность. '
                        .'Акты сверки доступны в личном кабинете — сверьте, пожалуйста, расчёты.',
                    'url' => url(route('cabinet.documents.index', [], false)),
                ],
            ));

            $sent++;
        }

        return $sent;
    }

    /**
     * @param  Collection<int, PrintedDocument>  $rows
     */
    private function summaryBody(Collection $rows): string
    {
        return 'За неделю мы выложили в личный кабинет актов сверки: '.$rows->count()
            .'. Открыть их можно в разделе «Документы».';
    }
}
