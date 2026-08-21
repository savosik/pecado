<?php

namespace App\Services\Notifications\Pulse;

use App\Models\ClientContact;
use App\Models\CrmEmailTemplate;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\NotificationDelivery;
use App\Models\NotificationSuppression;
use App\Models\User;
use App\Notifications\Pulse\PulseNotification;
use App\Notifications\Pulse\Support\PulseSignal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Сборка аудитории и отправка кампании.
 *
 * Письмо кампании проходит те же проверки, что и транзакционное: стоп-лист,
 * отписка, корректность адреса. Плюс своё — обязательное согласие контакта.
 * Реклама не обходит стоп-лист **по построению**, а не потому, что кто-то
 * не забыл проверить.
 */
class CampaignSender
{
    public function __construct(private readonly NotificationRenderer $renderer) {}

    /**
     * Собрать аудиторию по сегменту, не отправляя.
     *
     * @return array{eligible: int, skipped: array<string, int>}
     */
    public function buildAudience(NotificationCampaign $campaign): array
    {
        $campaign->recipients()->delete();

        $eligible = 0;
        $skipped = [];

        foreach ($this->candidates($campaign) as $candidate) {
            $reason = $this->reasonToSkip($candidate['email'], $candidate['contact']);

            NotificationCampaignRecipient::updateOrCreate(
                ['notification_campaign_id' => $campaign->id, 'email' => $candidate['email']],
                [
                    'client_user_id' => $candidate['client_user_id'],
                    'contact_id' => $candidate['contact']?->id,
                    'status' => $reason === null
                        ? NotificationCampaignRecipient::STATUS_PENDING
                        : NotificationCampaignRecipient::STATUS_SKIPPED,
                    'skip_reason' => $reason,
                ],
            );

            if ($reason === null) {
                $eligible++;
            } else {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
            }
        }

        $campaign->update([
            'recipients_total' => $eligible + array_sum($skipped),
            'recipients_skipped' => array_sum($skipped),
        ]);

        return ['eligible' => $eligible, 'skipped' => $skipped];
    }

    /**
     * Отправить порцию писем кампании.
     *
     * Порциями, а не разом: рассылка не должна выесть весь лимит отправки
     * и задержать транзакционные письма о заказах.
     *
     * @return array{sent: int, remaining: int}
     */
    public function sendBatch(NotificationCampaign $campaign, int $limit = 50): array
    {
        if ($campaign->status === NotificationCampaign::STATUS_DRAFT) {
            $campaign->update([
                'status' => NotificationCampaign::STATUS_SENDING,
                'started_at' => now(),
            ]);
        }

        $batch = $campaign->recipients()
            ->where('status', NotificationCampaignRecipient::STATUS_PENDING)
            ->limit($limit)
            ->get();

        foreach ($batch as $recipient) {
            $this->sendOne($campaign, $recipient);
        }

        $remaining = $campaign->recipients()
            ->where('status', NotificationCampaignRecipient::STATUS_PENDING)
            ->count();

        $campaign->update([
            'recipients_sent' => $campaign->recipients()
                ->where('status', NotificationCampaignRecipient::STATUS_SENT)
                ->count(),
            'status' => $remaining === 0 ? NotificationCampaign::STATUS_SENT : NotificationCampaign::STATUS_SENDING,
            'finished_at' => $remaining === 0 ? now() : null,
        ]);

        return ['sent' => $batch->count(), 'remaining' => $remaining];
    }

    private function sendOne(NotificationCampaign $campaign, NotificationCampaignRecipient $recipient): void
    {
        // Проверка повторяется перед самой отправкой: между сборкой аудитории
        // и рассылкой человек мог отписаться, и его письмо уходить не должно.
        $contact = $recipient->contact;
        $reason = $this->reasonToSkip($recipient->email, $contact);

        if ($reason !== null) {
            $recipient->update([
                'status' => NotificationCampaignRecipient::STATUS_SKIPPED,
                'skip_reason' => $reason,
            ]);

            return;
        }

        $signalUuid = (string) Str::uuid();

        $delivery = NotificationDelivery::create([
            'signal_uuid' => $signalUuid,
            'event_key' => 'campaigns.broadcast',
            'rule_name' => 'Кампания: '.$campaign->name,
            'client_user_id' => $recipient->client_user_id,
            'contact_id' => $recipient->contact_id,
            'channel' => 'email',
            'recipient' => $recipient->email,
            'recipient_kind' => 'campaign',
            'status' => NotificationDelivery::STATUS_QUEUED,
            'subject' => $campaign->subject,
            'queued_at' => now(),
        ]);

        $body = CrmEmailTemplate::render($campaign->body_html, [
            'client_name' => (string) ($recipient->client_user_id
                ? User::find($recipient->client_user_id)?->display_name
                : ''),
            'contact_name' => (string) ($contact?->full_name ?? ''),
        ]);

        Notification::route('mail', $recipient->email)->notify(new PulseNotification(
            signal: new PulseSignal(
                eventKey: 'campaigns.broadcast',
                clientUserId: $recipient->client_user_id,
                data: ['campaign_id' => $campaign->id, 'campaign_name' => $campaign->name],
                view: ['title' => $campaign->subject, 'body' => $body],
                uuid: $signalUuid,
            ),
            delivery: $delivery,
            subject: CrmEmailTemplate::render($campaign->subject, [
                'client_name' => (string) ($contact?->full_name ?? ''),
            ]),
            template: 'mail.pulse.campaign',
            unsubscribeUrl: $this->unsubscribeUrl($contact),
        ));

        $recipient->update([
            'status' => NotificationCampaignRecipient::STATUS_SENT,
            'notification_delivery_id' => $delivery->id,
        ]);
    }

    /**
     * Кандидаты аудитории по сегменту.
     *
     * @return Collection<int, array{email: string, client_user_id: int|null, contact: ClientContact|null}>
     */
    private function candidates(NotificationCampaign $campaign): Collection
    {
        $segment = (array) $campaign->segment;
        $roles = array_values(array_filter((array) ($segment['roles'] ?? [])));

        $contacts = ClientContact::query()
            ->deliverable()
            ->when($roles !== [], fn ($q) => $q->whereIn('role', $roles))
            ->when(filled($segment['client_status'] ?? null), fn ($q) => $q->whereHas(
                'user.crmProfile',
                fn ($p) => $p->where('lifecycle_status', $segment['client_status']),
            ))
            ->get()
            ->map(fn (ClientContact $c) => [
                'email' => $c->email,
                'client_user_id' => $c->user_id,
                'contact' => $c,
            ]);

        // Сами аккаунты партнёров добавляются, только если это явно попросили:
        // рассылка по ролям — про людей контрагента, а не про учётные записи.
        if ($segment['include_accounts'] ?? false) {
            $accounts = User::query()
                ->clients()
                ->where('is_subscribed', true)
                ->whereNotNull('email')
                ->get()
                ->map(fn (User $u) => [
                    'email' => $u->email,
                    'client_user_id' => $u->id,
                    'contact' => null,
                ]);

            $contacts = $contacts->concat($accounts);
        }

        return $contacts->unique('email')->values();
    }

    /**
     * Почему адресат не получит письмо кампании.
     */
    private function reasonToSkip(string $email, ?ClientContact $contact): ?string
    {
        if (NotificationSuppression::blocks($email, 'campaigns.broadcast')) {
            return NotificationDelivery::REASON_SUPPRESSED;
        }

        if ($contact !== null && $contact->unsubscribed_at !== null) {
            return NotificationDelivery::REASON_UNSUBSCRIBED;
        }

        // Реклама без согласия не уходит. Для учётной записи партнёра
        // согласие — это users.is_subscribed, проверенный при отборе.
        if ($contact !== null && ! $contact->marketing_consent) {
            return NotificationDelivery::REASON_NO_CONSENT;
        }

        return null;
    }

    private function unsubscribeUrl(?ClientContact $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        return url(route('subscriptions.unsubscribe', $contact->unsubscribe_token, false));
    }
}
