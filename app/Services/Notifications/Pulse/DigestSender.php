<?php

namespace App\Services\Notifications\Pulse;

use App\Models\NotificationDelivery;
use App\Models\NotificationSignal;
use App\Notifications\Pulse\PulseNotification;
use App\Notifications\Pulse\Support\PulseSignal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Сведение накопленных писем в одно.
 *
 * Мотив прикладной: 1С правит заказ построчно, и клиент получает десяток
 * писем об одном изменении. Троттлинг гасит это грубо — письма теряются;
 * дайджест правильно — они склеиваются.
 *
 * Доставки правила с digest != none копятся со статусом `queued` и без
 * фактической отправки; эта команда собирает накопленное по паре
 * (правило, адресат) в одно письмо.
 */
class DigestSender
{
    public function __construct(private readonly NotificationRenderer $renderer) {}

    /**
     * @return array{sent: int, collapsed: int}
     */
    public function send(string $period = 'hourly'): array
    {
        $pending = $this->pending($period);

        if ($pending->isEmpty()) {
            return ['sent' => 0, 'collapsed' => 0];
        }

        $sent = 0;
        $collapsed = 0;

        foreach ($pending->groupBy(fn (NotificationDelivery $d) => $d->notification_rule_id.'|'.$d->recipient) as $group) {
            $this->sendGroup($group);
            $sent++;
            $collapsed += $group->count();
        }

        return ['sent' => $sent, 'collapsed' => $collapsed];
    }

    /**
     * Доставки, ждущие сведения.
     *
     * @return Collection<int, NotificationDelivery>
     */
    private function pending(string $period): Collection
    {
        $since = $period === 'daily' ? now()->subDay() : now()->subHour();

        return NotificationDelivery::query()
            ->with('rule')
            ->where('status', NotificationDelivery::STATUS_QUEUED)
            ->whereNull('sent_at')
            ->where('created_at', '>=', $since->subDays(1))
            ->whereHas('rule', fn ($q) => $q->where('digest', $period))
            ->get();
    }

    /**
     * @param  Collection<int, NotificationDelivery>  $group
     */
    private function sendGroup(Collection $group): void
    {
        $first = $group->first();
        $rule = $first->rule;

        if ($rule === null) {
            return;
        }

        $signals = NotificationSignal::query()
            ->whereIn('uuid', $group->pluck('signal_uuid'))
            ->orderBy('id')
            ->get();

        // Одно событие в накоплении — дайджест не нужен, отправляем как есть:
        // заголовок «1 изменение» выглядел бы нелепо.
        $rows = $signals->count() === 1
            ? (array) ($signals->first()->view['rows'] ?? [])
            : $this->buildRows($signals);

        $title = $signals->count() === 1
            ? (string) ($signals->first()->view['title'] ?? $rule->name)
            : $this->digestTitle($signals);

        $signal = new PulseSignal(
            eventKey: $first->event_key,
            clientUserId: $first->client_user_id,
            companyId: $first->company_id,
            view: [
                'title' => $title,
                'body' => (string) ($signals->last()->view['body'] ?? ''),
                'rows' => $rows,
                'url' => $signals->last()->view['url'] ?? null,
            ],
        );

        Notification::route('mail', $first->recipient)->notify(new PulseNotification(
            signal: $signal,
            delivery: $first,
            subject: $title,
            template: 'mail.pulse.default',
            unsubscribeUrl: null,
        ));

        // Остальные доставки группы помечаются сведёнными: письмо по ним ушло
        // внутри дайджеста, и повторно слать их нельзя.
        NotificationDelivery::query()
            ->whereIn('id', $group->pluck('id'))
            ->whereKeyNot($first->id)
            ->update([
                'status' => NotificationDelivery::STATUS_SKIPPED,
                'skip_reason' => NotificationDelivery::REASON_DUPLICATE,
                'updated_at' => now(),
            ]);

        // Несущая доставка выбывает из следующей сборки: без этой отметки
        // очередной прогон собрал бы дайджест повторно. Статус остаётся
        // «в очереди» — факт отправки проставит журнал писем, уточнив время
        // и Message-ID.
        NotificationDelivery::query()
            ->whereKey($first->id)
            ->update(['sent_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  Collection<int, NotificationSignal>  $signals
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(Collection $signals): array
    {
        $rows = [];

        foreach ($signals as $signal) {
            $view = (array) $signal->view;

            $rows[] = [
                'type' => 'note',
                'text' => ($signal->created_at?->format('d.m.Y H:i') ?? '').' — '.($view['title'] ?? ''),
            ];

            foreach ((array) ($view['rows'] ?? []) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, NotificationSignal>  $signals
     */
    private function digestTitle(Collection $signals): string
    {
        $count = $signals->count();
        $label = $signals->last()->view['entity_label'] ?? null;

        $word = match (true) {
            $count % 10 === 1 && $count % 100 !== 11 => 'изменение',
            in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true) => 'изменения',
            default => 'изменений',
        };

        return $label
            ? sprintf('%d %s: %s', $count, $word, $label)
            : sprintf('%d %s', $count, $word);
    }
}
