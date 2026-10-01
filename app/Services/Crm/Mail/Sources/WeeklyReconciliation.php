<?php

namespace App\Services\Crm\Mail\Sources;

use App\Enums\PrintedDocumentType;
use App\Models\PrintedDocument;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Support\Cabinet\CabinetFinance;
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
                        'type' => 'note',
                        'text' => 'Акт сверки № '.$row->number.' от '.($row->date?->format('d.m.Y') ?? '—'),
                    ])->values()->all(),
                ],
            ));

            $sent++;
        }

        return $sent;
    }

    /**
     * Акты сверки тем, у кого есть просроченный долг.
     *
     * Отбор «только при долге» — **свойство самого события**, а не настройка
     * и не массовая подписка. Клиент один раз включает эту строку, и письмо
     * приходит только в те понедельники, когда долг действительно есть.
     *
     * Долг здесь — оплата, срок которой прошёл. Неоплаченная реализация внутри
     * отсрочки долгом не считается: письмо «за вами числится задолженность»
     * клиенту, который платит по договору вовремя, — неправда, и в кабинете
     * он просрочки не увидит (случай Адалт Тойс, сентябрь 2026).
     *
     * Иначе пришлось бы держать список должников где-то ещё и пересобирать
     * его по мере изменения — то есть вести вторую правду о том же факте.
     */
    private function debtorReminders(bool $dryRun): int
    {
        $debtors = SettlementEntry::query()
            // Заказ — это план, а не долг: долг создаёт отгрузка. Иначе
            // просроченный план заказа числился бы долгом навсегда — scopeOverdue
            // отсекает и его, и строки, срок которых ещё не наступил.
            ->overdue()
            ->whereNotNull('user_id')
            ->get(['user_id', 'nature', 'document_kind', 'amount', 'settled_amount', 'amount_rub', 'currency_code', 'date'])
            ->groupBy('user_id');

        $since = now()->subWeek();
        $sent = 0;
        $clients = $dryRun ? collect() : User::query()->whereKey($debtors->keys())->get()->keyBy('id');

        foreach ($debtors as $clientId => $lines) {
            $amount = round($lines->sum(fn ($line): float => (float) $line->unsettled_amount), 2);

            if ($amount <= 0) {
                continue;
            }

            if ($dryRun) {
                $sent++;

                continue;
            }

            $acts = PrintedDocument::query()
                ->where('type', PrintedDocumentType::RECONCILIATION_ACT->value)
                ->where('user_id', $clientId)
                ->where('created_at', '>=', $since)
                ->get(['number', 'date']);

            $this->stream->captureQuietly(new Occasion(
                key: 'documents.reconciliation_when_debt',
                clientUserId: (int) $clientId,
                data: [
                    'overdue_amount' => $amount,
                    'positions_count' => $lines->count(),
                    'documents_count' => $acts->count(),
                ],
                view: [
                    'title' => 'Акты сверки и состояние расчётов',
                    'body' => $this->debtBody($lines, $acts->count()),
                    // Кнопка ведёт туда, где долг виден: в «Оплаты», если раздел
                    // клиенту открыт, иначе — к актам сверки в «Документы».
                    'url' => url(route(
                        CabinetFinance::enabledFor($clients->get($clientId)) ? 'cabinet.payments.index' : 'cabinet.documents.index',
                        [],
                        false,
                    )),
                    'rows' => $acts->map(fn ($row): array => [
                        'type' => 'note',
                        'text' => 'Акт сверки № '.$row->number.' от '.($row->date?->format('d.m.Y') ?? '—'),
                    ])->values()->all(),
                ],
            ));

            $sent++;
        }

        return $sent;
    }

    /**
     * @param  Collection<int, SettlementEntry>  $lines  просроченные строки регистра
     */
    private function debtBody(Collection $lines, int $actsCount): string
    {
        $oldest = $lines->min('date');
        $debt = sprintf(
            'По нашим данным у вас есть просроченная оплата: документов — %d, самый ранний срок — %s.',
            $lines->count(),
            $oldest ? Carbon::parse($oldest)->format('d.m.Y') : '—',
        );

        return $actsCount === 0
            ? $debt.' Если платёж уже отправлен, просто перешлите нам платёжное поручение. Сверить расчёты можно в личном кабинете.'
            : $debt.' За неделю мы выложили актов сверки: '.$actsCount.' — сверьте, пожалуйста, расчёты.';
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
