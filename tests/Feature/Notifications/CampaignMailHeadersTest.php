<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\Notifications\Pulse\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Заголовки письма кампании.
 *
 * Отдельный класс без Notification::fake(): заголовки существуют только
 * в собранном письме, а фейк до сборки не доходит.
 *
 * List-Unsubscribe обязателен для рекламы: почтовые клиенты показывают по нему
 * кнопку отписки, и без неё единственным способом прекратить рассылку остаётся
 * жалоба на спам — а она бьёт по репутации домена для всех писем, включая
 * уведомления о заказах.
 */
class CampaignMailHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[TestDox('Письмо кампании несёт машиночитаемую отписку')]
    public function campaign_mail_carries_list_unsubscribe(): void
    {
        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.mode' => 'live',
            'notification_pulse.domains.campaigns.enabled' => true,
            'mail.default' => 'array',
        ]);

        $partner = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $partner->id]);

        ClientContact::factory()->role(ClientContactRole::BUYER)->create([
            'user_id' => $partner->id,
            'company_id' => $company->id,
            'email' => 'buyer@x.ru',
            'marketing_consent' => true,
        ]);

        $campaign = NotificationCampaign::create([
            'name' => 'Акция',
            'segment' => ['roles' => ['buyer']],
            'subject' => 'Скидки',
            'body_html' => '<p>Текст</p>',
            'status' => NotificationCampaign::STATUS_DRAFT,
        ]);

        app(CampaignSender::class)->buildAudience($campaign);
        app(CampaignSender::class)->sendBatch($campaign);

        $messages = app('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages, 'письмо должно было уйти');

        $headers = $messages->first()->getOriginalMessage()->getHeaders();

        $this->assertTrue($headers->has('List-Unsubscribe'));
        $this->assertTrue($headers->has('List-Unsubscribe-Post'));
        $this->assertStringContainsString(
            'unsubscribe',
            $headers->get('List-Unsubscribe')->getBodyAsString(),
        );
    }

    #[Test]
    #[TestDox('Транзакционное письмо машиночитаемой отписки не несёт')]
    public function transactional_mail_has_no_list_unsubscribe(): void
    {
        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.mode' => 'live',
            'notification_pulse.domains.orders.enabled' => true,
            'mail.default' => 'array',
        ]);

        $partner = User::factory()->create(['email' => 'client@x.ru']);
        $company = Company::factory()->create(['user_id' => $partner->id]);

        $rule = \App\Models\NotificationRule::factory()->forCompany($company->id)->create([
            'event_key' => 'orders.shipped',
        ]);
        $rule->recipients()->create([
            'kind' => \App\Models\NotificationRuleRecipient::KIND_EMAIL,
            'value' => 'buh@x.ru',
        ]);

        app(\App\Services\Notifications\Pulse\NotificationPulse::class)->signal(
            new \App\Notifications\Pulse\Support\PulseSignal(
                eventKey: 'orders.shipped',
                clientUserId: $partner->id,
                companyId: $company->id,
                data: ['amount' => 100],
                view: ['title' => 'Отгрузка'],
            )
        );

        $headers = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHeaders();

        // Уведомление о заказе — не реклама: кнопка «отписаться» в почтовом
        // клиенте отключила бы клиенту важные письма одним нажатием.
        $this->assertFalse($headers->has('List-Unsubscribe'));
    }
}
