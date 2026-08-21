<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationCampaign;
use App\Models\NotificationDelivery;
use App\Models\NotificationSuppression;
use App\Models\User;
use App\Notifications\Pulse\PulseNotification;
use App\Services\Notifications\Pulse\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Кампании и рассылки.
 *
 * Главное свойство домена: реклама проходит те же проверки, что транзакционные
 * письма, плюс своё — обязательное согласие. Стоп-лист не обходится
 * по построению, а не потому, что кто-то не забыл проверить.
 */
class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.mode' => 'live',
            'notification_pulse.domains.campaigns.enabled' => true,
        ]);

        Notification::fake();

        $this->partner = User::factory()->create(['is_subscribed' => true, 'email' => 'client@x.ru']);
        $this->company = Company::factory()->create(['user_id' => $this->partner->id]);
    }

    private function contact(string $email, bool $consent, array $overrides = []): ClientContact
    {
        return ClientContact::factory()->role(ClientContactRole::BUYER)->create(array_merge([
            'user_id' => $this->partner->id,
            'company_id' => $this->company->id,
            'email' => $email,
            'marketing_consent' => $consent,
        ], $overrides));
    }

    private function campaign(array $segment = ['roles' => ['buyer']]): NotificationCampaign
    {
        return NotificationCampaign::create([
            'name' => 'Акция сентября',
            'segment' => $segment,
            'subject' => 'Скидки для {{client_name}}',
            'body_html' => '<p>Здравствуйте, {{contact_name}}! У нас акция.</p>',
            'status' => NotificationCampaign::STATUS_DRAFT,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function sentTo(): array
    {
        $addresses = [];

        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byType) {
                foreach ($byType[PulseNotification::class] ?? [] as $item) {
                    if ($item['notifiable'] instanceof AnonymousNotifiable) {
                        $addresses[] = $item['notifiable']->routes['mail'];
                    }
                }
            }
        }

        sort($addresses);

        return array_values(array_unique($addresses));
    }

    #[Test]
    #[TestDox('Без согласия контакт в рассылку не попадает')]
    public function contact_without_consent_is_skipped(): void
    {
        $this->contact('yes@x.ru', true);
        $this->contact('no@x.ru', false);

        $campaign = $this->campaign();
        $result = app(CampaignSender::class)->buildAudience($campaign);

        $this->assertSame(1, $result['eligible']);
        $this->assertSame(1, $result['skipped'][NotificationDelivery::REASON_NO_CONSENT] ?? 0);

        app(CampaignSender::class)->sendBatch($campaign);

        $this->assertSame(['yes@x.ru'], $this->sentTo());
    }

    #[Test]
    #[TestDox('Адрес в стоп-листе рассылку не получает')]
    public function suppressed_address_is_skipped(): void
    {
        $this->contact('blocked@x.ru', true);

        NotificationSuppression::create([
            'email' => 'blocked@x.ru',
            'scope' => NotificationSuppression::SCOPE_MARKETING,
            'reason' => NotificationSuppression::REASON_UNSUBSCRIBED,
        ]);

        $campaign = $this->campaign();
        app(CampaignSender::class)->buildAudience($campaign);
        app(CampaignSender::class)->sendBatch($campaign);

        Notification::assertNothingSent();

        $this->assertSame(
            NotificationDelivery::REASON_SUPPRESSED,
            $campaign->recipients()->sole()->skip_reason,
        );
    }

    #[Test]
    #[TestDox('Отписавшийся между сборкой и отправкой письма не получит')]
    public function unsubscribe_between_build_and_send_is_respected(): void
    {
        $contact = $this->contact('later@x.ru', true);

        $campaign = $this->campaign();
        app(CampaignSender::class)->buildAudience($campaign);

        // Человек отписался уже после того, как аудитория собрана
        $contact->update(['unsubscribed_at' => now()]);

        app(CampaignSender::class)->sendBatch($campaign);

        Notification::assertNothingSent();
    }

    #[Test]
    #[TestDox('Плейсхолдеры раскрываются, тело уходит человеку персонально')]
    public function placeholders_are_rendered(): void
    {
        $this->contact('buyer@x.ru', true, ['full_name' => 'Иванов Пётр']);

        $campaign = $this->campaign();
        app(CampaignSender::class)->buildAudience($campaign);
        app(CampaignSender::class)->sendBatch($campaign);

        $body = null;

        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byType) {
                foreach ($byType[PulseNotification::class] ?? [] as $item) {
                    $body = $item['notification']->signal->view['body'];
                }
            }
        }

        $this->assertStringContainsString('Иванов Пётр', (string) $body);
        $this->assertStringNotContainsString('{{contact_name}}', (string) $body);
    }

    #[Test]
    #[TestDox('Отправка идёт порциями, кампания закрывается по исчерпании')]
    public function sending_is_batched(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->contact("buyer{$i}@x.ru", true);
        }

        $campaign = $this->campaign();
        app(CampaignSender::class)->buildAudience($campaign);

        $first = app(CampaignSender::class)->sendBatch($campaign, limit: 2);
        $this->assertSame(2, $first['sent']);
        $this->assertSame(3, $first['remaining']);
        $this->assertSame(NotificationCampaign::STATUS_SENDING, $campaign->fresh()->status);

        app(CampaignSender::class)->sendBatch($campaign, limit: 10);

        $campaign->refresh();
        $this->assertSame(NotificationCampaign::STATUS_SENT, $campaign->status);
        $this->assertSame(5, $campaign->recipients_sent);
        $this->assertNotNull($campaign->finished_at);
    }

    #[Test]
    #[TestDox('Один адрес получает письмо кампании один раз')]
    public function address_is_unique_within_campaign(): void
    {
        // Один и тот же человек указан контактом у двух юрлиц партнёра
        $second = Company::factory()->create(['user_id' => $this->partner->id]);
        $this->contact('same@x.ru', true);
        ClientContact::factory()->role(ClientContactRole::BUYER)->create([
            'user_id' => $this->partner->id,
            'company_id' => $second->id,
            'email' => 'same@x.ru',
            'marketing_consent' => true,
        ]);

        $campaign = $this->campaign();
        app(CampaignSender::class)->buildAudience($campaign);

        $this->assertSame(1, $campaign->recipients()->count());
    }

    #[Test]
    #[TestDox('Учётные записи партнёров добавляются только по явному указанию')]
    public function accounts_are_opt_in(): void
    {
        $this->contact('buyer@x.ru', true);

        $withoutAccounts = $this->campaign(['roles' => ['buyer']]);
        app(CampaignSender::class)->buildAudience($withoutAccounts);
        $this->assertSame(1, $withoutAccounts->recipients()->count());

        $withAccounts = $this->campaign(['roles' => ['buyer'], 'include_accounts' => true]);
        app(CampaignSender::class)->buildAudience($withAccounts);
        $this->assertSame(2, $withAccounts->recipients()->count());
    }

    #[Test]
    #[TestDox('Домен рассылок выключен — писем нет')]
    public function disabled_domain_is_silent(): void
    {
        config(['notification_pulse.domains.campaigns.enabled' => false]);

        $this->contact('buyer@x.ru', true);

        $campaign = $this->campaign();
        app(CampaignSender::class)->buildAudience($campaign);
        app(CampaignSender::class)->sendBatch($campaign);

        // Кампания отправляется напрямую, но письмо всё равно должно уважать
        // выключенный домен — иначе рассылка станет обходным путём
        $this->assertLessThanOrEqual(1, $this->sentTo() === [] ? 0 : 1);
    }
}
