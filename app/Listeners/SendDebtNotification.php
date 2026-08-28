<?php

namespace App\Listeners;

use App\Enums\DebtLevel;
use App\Events\DebtLevelChanged;
use App\Models\DebtState;
use App\Models\SettlementEntry;
use App\Services\Crm\Mail\MailStream;
use App\Support\Cabinet\CabinetFinance;
use App\Support\Debt\DebtControl;
use App\Support\Notifications\Occasion;
use Carbon\CarbonImmutable;

/**
 * Письмо клиенту на переход ступени лестницы долга (карточка debt-03).
 *
 * Домен сообщает «ступень сменилась», кому слать — решает матрица уведомлений
 * по типу `finance.debt_*`. Ключ эпизода (контрагент + ступень + дата) держит
 * `MailStream`: повторный пересчёт письма не дублирует.
 */
class SendDebtNotification
{
    public function __construct(private readonly MailStream $stream) {}

    public function handle(DebtLevelChanged $event): void
    {
        if (! DebtControl::live(DebtControl::ACTION_MAIL)) {
            return;
        }

        $key = $this->occasionKey($event);

        if ($key === null) {
            return;
        }

        $state = $event->state;
        $lines = $this->overdueLines($state);

        $this->stream->captureQuietly(new Occasion(
            key: $key,
            clientUserId: $state->user_id,
            companyId: $state->company_id,
            subject: $state,
            data: [
                'level' => $state->level->value,
                'previous_level' => $event->from->value,
                'since' => $state->since?->toDateString(),
                'overdue_amount' => (float) $state->overdue_amount,
                'amount' => (float) $state->overdue_amount,
                'days_overdue' => $state->age_days,
                'oldest_due_date' => $state->oldest_due_date?->toDateString(),
                'positions_count' => $state->lines_count,
            ],
            view: [
                'title' => $this->title($state->level, $event->from),
                'body' => $this->body($state, $event->from),
                'rows' => $lines,
                'url' => $this->url($state),
            ],
        ));
    }

    private function occasionKey(DebtLevelChanged $event): ?string
    {
        if ($event->to === DebtLevel::CLEAN) {
            // «Погашено» пишем только тем, кого о просрочке предупреждали.
            return $event->from->isVisible() ? 'finance.debt_cleared' : null;
        }

        // Смягчение с hold до no_orders и т. п. письма не требует: клиент
        // узнает об этом из «погашено», когда дойдёт до конца.
        if (! $event->isEscalation()) {
            return null;
        }

        return 'finance.debt_'.$event->to->value;
    }

    private function title(DebtLevel $level, DebtLevel $from): string
    {
        return match ($level) {
            DebtLevel::CLEAN => 'Оплата получена — ограничения сняты',
            DebtLevel::OVERDUE => 'Просроченная оплата',
            DebtLevel::NO_PREORDERS => 'Оформление предзаказов приостановлено',
            DebtLevel::NO_ORDERS => 'Оформление заказов приостановлено',
            DebtLevel::HOLD => 'Отгрузки приостановлены',
        };
    }

    private function body(DebtState $state, DebtLevel $from): string
    {
        $company = $state->company?->name;
        $amount = number_format((float) $state->overdue_amount, 2, ',', ' ');
        $oldest = $state->oldest_due_date?->format('d.m.Y') ?? '—';
        $who = $company ? sprintf('по контрагенту «%s»', $company) : 'по вашим расчётам';

        return match ($state->level) {
            DebtLevel::CLEAN => sprintf(
                'Спасибо: просроченная задолженность %s погашена, все ограничения на оформление заказов сняты. Акт сверки — во вложении и в личном кабинете.',
                $who,
            ),
            DebtLevel::OVERDUE => sprintf(
                'По нашим данным %s есть просроченная оплата на %s ₽ (самый ранний срок — %s). Если платёж уже отправлен, просто перешлите нам платёжное поручение. Акт сверки — во вложении.',
                $who, $amount, $oldest,
            ),
            DebtLevel::NO_PREORDERS => sprintf(
                'Просроченная оплата %s на %s ₽ не погашена (срок — %s, %d дн.). До погашения оформление предзаказов приостановлено; обычные заказы работают. Акт сверки — во вложении.',
                $who, $amount, $oldest, $state->age_days,
            ),
            DebtLevel::NO_ORDERS => sprintf(
                'Просроченная оплата %s на %s ₽ остаётся непогашенной %d дн. (срок — %s). Оформление заказов от этого контрагента приостановлено до поступления оплаты — ограничение снимается автоматически в день платежа. Акт сверки — во вложении.',
                $who, $amount, $state->age_days, $oldest,
            ),
            DebtLevel::HOLD => sprintf(
                'Задолженность %s на %s ₽ не погашается %d дн. (срок — %s). Отгрузки приостановлены до полного погашения; менеджер свяжется с вами для согласования порядка оплаты. Акт сверки — во вложении.',
                $who, $amount, $state->age_days, $oldest,
            ),
        };
    }

    /**
     * Просроченные строки регистра по паре — чтобы бухгалтеру не искать,
     * что именно оплатить.
     *
     * @return list<array{type: string, text: string}>
     */
    private function overdueLines(DebtState $state): array
    {
        if ($state->level === DebtLevel::CLEAN || $state->company_id === null) {
            return [];
        }

        $today = CarbonImmutable::today();

        return SettlementEntry::query()
            ->overdue()
            ->where('user_id', $state->user_id)
            ->where('company_id', $state->company_id)
            ->orderBy('date')
            ->limit(10)
            ->get()
            ->map(fn (SettlementEntry $line): array => [
                'type' => 'note',
                'text' => sprintf(
                    '%s от %s — срок %s (%d дн.), к оплате %s ₽',
                    $line->document_number ? 'Документ № '.$line->document_number : 'Строка расчётов',
                    $line->document_date?->format('d.m.Y') ?? '—',
                    $line->date?->format('d.m.Y') ?? '—',
                    $line->date ? (int) CarbonImmutable::instance($line->date)->diffInDays($today) : 0,
                    number_format((float) $line->unsettled_amount, 2, ',', ' '),
                ),
            ])
            ->all();
    }

    private function url(DebtState $state): string
    {
        $user = $state->user;

        if ($user !== null && CabinetFinance::enabledFor($user)) {
            return url(route('cabinet.payments.index', [], false));
        }

        return url(route('cabinet.documents.index', [], false));
    }
}
