<?php

namespace Tests\Feature\Notifications;

use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\NotificationSuppression;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Pulse\PulseNotification;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Notifications\Pulse\DigestSender;
use App\Services\Notifications\Pulse\NotificationPulse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Гигиена рассылки: сведение писем, тихие часы, стоп-лист.
 *
 * Главная проверка — серия правок заказа из 1С должна давать одно письмо,
 * а не десяток: иначе уведомления превращаются в шум, который перестают читать.
 */
class HygieneTest extends TestCase
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

        Notification::fake();

        $this->partner = User::factory()->create(['email' => 'client@x.ru']);
        $this->company = Company::factory()->create(['user_id' => $this->partner->id]);
    }

    private function rule(array $attributes = []): NotificationRule
    {
        $rule = NotificationRule::factory()->forCompany($this->company->id)->create(array_merge([
            'name' => 'Изменения заказа',
            'event_key' => 'orders.items_updated',
        ], $attributes));

        $rule->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_EMAIL,
            'value' => 'buyer@x.ru',
        ]);

        return $rule;
    }

    private function fire(int $index = 1): array
    {
        return app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: 'orders.items_updated',
            clientUserId: $this->partner->id,
            companyId: $this->company->id,
            data: ['added_count' => $index],
            view: [
                'title' => "Изменение {$index}",
                'entity_label' => 'Заказ №42',
                'rows' => [['type' => 'action', 'kind' => 'added', 'label' => 'Добавлен', 'text' => "Товар {$index}"]],
            ],
        ));
    }

    private function sentCount(): int
    {
        $count = 0;

        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byType) {
                $count += count($byType[PulseNotification::class] ?? []);
            }
        }

        return $count;
    }

    /**
     * @return array<int, string>
     */
    private function sentSubjects(): array
    {
        $subjects = [];

        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byType) {
                foreach ($byType[PulseNotification::class] ?? [] as $item) {
                    $subjects[] = $item['notification']->subject;
                }
            }
        }

        return $subjects;
    }

    #[Test]
    #[TestDox('Серия правок со сведением даёт одно письмо вместо десяти')]
    public function digest_collapses_series(): void
    {
        $this->rule(['digest' => 'hourly']);

        for ($i = 1; $i <= 10; $i++) {
            $this->fire($i);
        }

        // Пока сведение не отправлено, писем нет вовсе
        Notification::assertNothingSent();
        $this->assertSame(10, NotificationDelivery::where('status', NotificationDelivery::STATUS_QUEUED)->count());

        $result = app(DigestSender::class)->send('hourly');

        $this->assertSame(1, $result['sent']);
        $this->assertSame(10, $result['collapsed']);
        $this->assertSame(1, $this->sentCount(), 'должно уйти ровно одно письмо');

        // Заголовок называет число событий
        $this->assertStringContainsString('10 изменений', $this->sentSubjects()[0]);
    }

    #[Test]
    #[TestDox('Одно событие в накоплении уходит обычным письмом, а не «1 изменение»')]
    public function single_event_is_not_labelled_as_digest(): void
    {
        $this->rule(['digest' => 'hourly']);
        $this->fire(1);

        app(DigestSender::class)->send('hourly');

        $this->assertSame(1, $this->sentCount());
        $this->assertStringNotContainsString('1 изменение', $this->sentSubjects()[0]);
        $this->assertStringContainsString('Изменение 1', $this->sentSubjects()[0]);
    }

    #[Test]
    #[TestDox('Свёрнутые доставки помечаются, чтобы не уйти повторно')]
    public function collapsed_deliveries_are_marked(): void
    {
        $this->rule(['digest' => 'hourly']);
        $this->fire(1);
        $this->fire(2);

        app(DigestSender::class)->send('hourly');

        $this->assertSame(1, NotificationDelivery::where('skip_reason', NotificationDelivery::REASON_DUPLICATE)->count());

        // Повторный запуск не шлёт второе письмо
        app(DigestSender::class)->send('hourly');
        $this->assertSame(1, $this->sentCount());
    }

    #[Test]
    #[TestDox('Правило без сведения шлёт сразу')]
    public function rule_without_digest_sends_immediately(): void
    {
        $this->rule();
        $this->fire();

        $this->assertSame(1, $this->sentCount());
    }

    #[Test]
    #[TestDox('В тихие часы письмо откладывается, а не теряется')]
    public function quiet_hours_defer_delivery(): void
    {
        $hour = (int) now()->format('H');
        $from = str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00';
        $to = str_pad((string) (($hour + 2) % 24), 2, '0', STR_PAD_LEFT).':00';

        $this->rule(['quiet_hours' => ['from' => $from, 'to' => $to]]);
        $this->fire();

        Notification::assertNothingSent();

        // Доставка осталась в очереди — это отсрочка, а не отказ
        $delivery = NotificationDelivery::sole();
        $this->assertSame(NotificationDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertNull($delivery->skip_reason);
    }

    #[Test]
    #[TestDox('Вне тихих часов письмо уходит сразу')]
    public function outside_quiet_hours_sends(): void
    {
        $hour = (int) now()->format('H');
        $from = str_pad((string) (($hour + 3) % 24), 2, '0', STR_PAD_LEFT).':00';
        $to = str_pad((string) (($hour + 5) % 24), 2, '0', STR_PAD_LEFT).':00';

        $this->rule(['quiet_hours' => ['from' => $from, 'to' => $to]]);
        $this->fire();

        $this->assertSame(1, $this->sentCount());
    }

    #[Test]
    #[TestDox('Жёсткий отказ сервера кладёт адрес в стоп-лист')]
    public function hard_bounce_suppresses_address(): void
    {
        $this->rule();
        $this->fire();

        $delivery = NotificationDelivery::sole();

        app(\App\Listeners\RecordMailBounce::class)
            ->handleFailure($delivery->id, 'SMTP 550 5.1.1 User unknown');

        $this->assertTrue(NotificationSuppression::where('email', 'buyer@x.ru')->exists());
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->fresh()->status);
    }

    #[Test]
    #[TestDox('Мягкий отказ адрес не вычёркивает: завтра он может заработать')]
    public function soft_bounce_keeps_address(): void
    {
        $this->rule();
        $this->fire();

        app(\App\Listeners\RecordMailBounce::class)
            ->handleFailure(NotificationDelivery::sole()->id, 'SMTP 452 Mailbox full, try again later');

        $this->assertFalse(NotificationSuppression::where('email', 'buyer@x.ru')->exists());
    }

    #[Test]
    #[TestDox('Стоп-лист доступен в интерфейсе, снятие — под правом руководителя')]
    public function suppressions_screen_respects_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $manager->id]);

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $record = NotificationSuppression::create([
            'email' => 'gone@x.ru',
            'scope' => NotificationSuppression::SCOPE_ALL,
            'reason' => NotificationSuppression::REASON_BOUNCE,
        ]);

        $this->actingAs($manager)->get('/crm/notifications/suppressions')->assertOk();

        // Менеджер видит, но не снимает: возврат битого адреса — осознанное решение
        $this->actingAs($manager)
            ->delete("/crm/notifications/suppressions/{$record->id}")
            ->assertForbidden();

        $this->actingAs($head)
            ->delete("/crm/notifications/suppressions/{$record->id}")
            ->assertRedirect();

        $this->assertNull($record->fresh());
    }
}
