<?php

namespace Tests\Feature\Notifications;

use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\NotificationSuppression;
use App\Models\SentEmail;
use App\Models\User;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Notifications\Pulse\NotificationPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Гарды доставки: каждый отказ виден в журнале с причиной.
 *
 * Молчаливый пропуск означал бы возврат к состоянию «выясняется через жалобу
 * клиента», ради ухода от которого эпик и затевался.
 */
class DeliveryGuardTest extends TestCase
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
            'notification_pulse.live_events' => [],
            'notification_pulse.domains.orders.enabled' => true,
        ]);

        $this->partner = User::factory()->create(['email' => 'client@romashka.ru']);
        $this->company = Company::factory()->create(['user_id' => $this->partner->id]);
    }

    private function ruleTo(string $email, array $attributes = []): NotificationRule
    {
        $rule = NotificationRule::factory()->forCompany($this->company->id)->create(array_merge([
            'name' => 'Правило',
            'event_key' => 'orders.shipped',
        ], $attributes));

        $rule->recipients()->create(['kind' => NotificationRuleRecipient::KIND_EMAIL, 'value' => $email]);

        return $rule;
    }

    private function fire(?\Carbon\CarbonInterface $occurredAt = null): array
    {
        return app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: 'orders.shipped',
            clientUserId: $this->partner->id,
            companyId: $this->company->id,
            data: ['amount' => 100],
            view: ['title' => 'Отгрузка'],
            occurredAt: $occurredAt,
        ));
    }

    private function delivery(string $uuid): NotificationDelivery
    {
        return NotificationDelivery::where('signal_uuid', $uuid)->sole();
    }

    #[Test]
    #[TestDox('Адрес в стоп-листе пропускается с причиной')]
    public function suppressed_address_is_skipped(): void
    {
        Notification::fake();
        $this->ruleTo('blocked@romashka.ru');

        NotificationSuppression::create([
            'email' => 'blocked@romashka.ru',
            'scope' => NotificationSuppression::SCOPE_ALL,
            'reason' => NotificationSuppression::REASON_BOUNCE,
        ]);

        $result = $this->fire();

        Notification::assertNothingSent();
        $this->assertSame(NotificationDelivery::REASON_SUPPRESSED, $this->delivery($result['signal_uuid'])->skip_reason);
    }

    #[Test]
    #[TestDox('Отписка от рассылок не отключает уведомления о заказах')]
    public function marketing_optout_keeps_transactional(): void
    {
        Notification::fake();
        $this->ruleTo('buh@romashka.ru');

        NotificationSuppression::create([
            'email' => 'buh@romashka.ru',
            'scope' => NotificationSuppression::SCOPE_MARKETING,
            'reason' => NotificationSuppression::REASON_UNSUBSCRIBED,
        ]);

        $result = $this->fire();

        $this->assertSame(NotificationDelivery::STATUS_QUEUED, $this->delivery($result['signal_uuid'])->status);
    }

    #[Test]
    #[TestDox('Отписавшийся контакт писем не получает')]
    public function unsubscribed_contact_is_skipped(): void
    {
        Notification::fake();

        $contact = ClientContact::factory()->unsubscribed()->create([
            'user_id' => $this->partner->id,
            'company_id' => $this->company->id,
            'email' => 'gone@romashka.ru',
        ]);

        $rule = NotificationRule::factory()->forCompany($this->company->id)->create([
            'name' => 'Контакту',
            'event_key' => 'orders.shipped',
        ]);
        $rule->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_CONTACT,
            'contact_id' => $contact->id,
        ]);

        $this->fire();

        Notification::assertNothingSent();
    }

    #[Test]
    #[TestDox('Некорректный адрес пропускается с причиной')]
    public function invalid_email_is_skipped(): void
    {
        Notification::fake();
        $this->ruleTo('не-адрес');

        $result = $this->fire();

        Notification::assertNothingSent();
        $this->assertSame(NotificationDelivery::REASON_INVALID_EMAIL, $this->delivery($result['signal_uuid'])->skip_reason);
    }

    #[Test]
    #[TestDox('Троттлинг не даёт слать одному адресу чаще заданного')]
    public function throttle_limits_repeat(): void
    {
        Notification::fake();
        $this->ruleTo('buh@romashka.ru', ['throttle_seconds' => 300]);

        $first = $this->fire();
        $second = $this->fire();

        $this->assertSame(NotificationDelivery::STATUS_QUEUED, $this->delivery($first['signal_uuid'])->status);
        $this->assertSame(NotificationDelivery::REASON_THROTTLED, $this->delivery($second['signal_uuid'])->skip_reason);
        $this->assertSame(1, $second['skipped']);
    }

    #[Test]
    #[TestDox('Старое событие не рассылается — защита от выгрузки истории')]
    public function stale_signal_is_not_delivered(): void
    {
        Notification::fake();
        $this->ruleTo('buh@romashka.ru');

        config(['notification_pulse.limits.max_signal_age_minutes' => 60]);

        $result = $this->fire(now()->subHours(5));

        Notification::assertNothingSent();
        $this->assertSame(NotificationDelivery::REASON_TOO_OLD, $this->delivery($result['signal_uuid'])->skip_reason);
    }

    #[Test]
    #[TestDox('В теневом режиме получатели считаются, но письма не уходят')]
    public function shadow_mode_computes_without_sending(): void
    {
        Notification::fake();
        config(['notification_pulse.mode' => 'shadow']);
        $this->ruleTo('buh@romashka.ru');

        $result = $this->fire();

        Notification::assertNothingSent();

        $delivery = $this->delivery($result['signal_uuid']);
        $this->assertSame(NotificationDelivery::REASON_SHADOW, $delivery->skip_reason);
        // Адресат посчитан — это и есть предмет сверки перед переходом
        $this->assertSame('buh@romashka.ru', $delivery->recipient);
    }

    #[Test]
    #[TestDox('Предпросмотр показывает адресатов и ничего не отправляет')]
    public function preview_sends_nothing(): void
    {
        Notification::fake();
        $this->ruleTo('buh@romashka.ru');

        $result = app(NotificationPulse::class)->preview(new PulseSignal(
            eventKey: 'orders.shipped',
            clientUserId: $this->partner->id,
            companyId: $this->company->id,
            data: ['amount' => 100],
        ));

        Notification::assertNothingSent();
        $this->assertSame(1, $result['matched']);
        $this->assertSame(NotificationDelivery::REASON_DRY_RUN, $this->delivery($result['signal_uuid'])->skip_reason);
    }

    #[Test]
    #[TestDox('Выключенный домен сигналов не принимает')]
    public function disabled_domain_accepts_nothing(): void
    {
        Notification::fake();
        config(['notification_pulse.domains.orders.enabled' => false]);
        $this->ruleTo('buh@romashka.ru');

        $result = $this->fire();

        Notification::assertNothingSent();
        $this->assertNull($result['signal_uuid']);
    }

    #[Test]
    #[TestDox('Письмо связывается с решением пульта через журнал писем')]
    public function sent_email_is_linked_to_delivery(): void
    {
        // Без фейка: нужна настоящая отправка, чтобы сработал MessageSent
        config(['mail.default' => 'array', 'notifications.mail.journal_enabled' => true]);
        $this->ruleTo('buh@romashka.ru');

        $result = $this->fire();

        $delivery = $this->delivery($result['signal_uuid']);
        $journal = SentEmail::where('recipient', 'buh@romashka.ru')->sole();

        $this->assertSame($delivery->id, $journal->notification_delivery_id);
        $this->assertSame($this->partner->id, $journal->client_user_id);

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        // Message-ID копируется в доставку, чтобы пережить ретенцию журнала
        // писем. Тестовый транспорт его не проставляет, поэтому сверяем
        // равенство источнику, а не наличие значения.
        $this->assertSame($journal->message_id, $delivery->message_id);
    }
}
