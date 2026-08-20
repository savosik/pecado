<?php

namespace Tests\Feature\Notifications;

use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\NotificationSignal;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Notifications\Pulse\NotificationPulse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Журнал уведомлений и трасса сигнала.
 *
 * Проверяем то, ради чего журнал заводился: отрицательные исходы видны,
 * трасса объясняет, почему письмо не ушло, а чужие клиенты не видны.
 */
class JournalTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $partner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.mode' => 'live',
            'notification_pulse.domains.orders.enabled' => true,
        ]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->partner = User::factory()->create(['personal_manager_id' => $card->id, 'email' => 'client@x.ru']);
        $this->company = Company::factory()->create(['user_id' => $this->partner->id]);
    }

    private function fireWithRules(): array
    {
        Notification::fake();

        $stop = NotificationRule::factory()->forCompany($this->company->id)->stopping()->priority(50)->create([
            'name' => 'Закрытие — директору',
            'event_key' => 'orders.status_changed',
            'conditions' => ['field' => 'status', 'op' => '=', 'value' => 'closed'],
        ]);
        $stop->recipients()->create(['kind' => NotificationRuleRecipient::KIND_EMAIL, 'value' => 'dir@x.ru']);

        $client = NotificationRule::factory()->forCompany($this->company->id)->priority(100)->create([
            'name' => 'Статус — клиенту',
            'event_key' => 'orders.status_changed',
        ]);
        $client->recipients()->create(['kind' => NotificationRuleRecipient::KIND_CLIENT_USER]);

        return app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: 'orders.status_changed',
            clientUserId: $this->partner->id,
            companyId: $this->company->id,
            data: ['status' => 'closed', 'order_number' => 'T-1'],
            view: ['title' => 'Заказ закрыт'],
        ));
    }

    #[Test]
    #[TestDox('Журнал показывает отправленное и пропущенное с причиной')]
    public function journal_lists_outcomes(): void
    {
        Notification::fake();

        $rule = NotificationRule::factory()->forCompany($this->company->id)->create([
            'event_key' => 'orders.shipped',
            'throttle_seconds' => 600,
        ]);
        $rule->recipients()->create(['kind' => NotificationRuleRecipient::KIND_EMAIL, 'value' => 'buh@x.ru']);

        $signal = fn () => app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: 'orders.shipped',
            clientUserId: $this->partner->id,
            companyId: $this->company->id,
            data: ['amount' => 100],
        ));

        $signal();
        $signal();

        $this->actingAs($this->manager)
            ->get('/crm/notifications/journal')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Notifications/Journal')
                ->has('deliveries.data', 2)
            );

        $skipped = NotificationDelivery::where('status', NotificationDelivery::STATUS_SKIPPED)->sole();
        $this->assertSame(NotificationDelivery::REASON_THROTTLED, $skipped->skip_reason);
    }

    #[Test]
    #[TestDox('Трасса показывает сработавшее правило и остановленный разбор')]
    public function trace_explains_stop(): void
    {
        $result = $this->fireWithRules();

        $this->actingAs($this->manager)
            ->get("/crm/notifications/signals/{$result['signal_uuid']}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Notifications/SignalTrace')
                ->where('signal.matched_rules_count', 1)
                ->has('rules', 2)
                // Первое правило сработало и остановило разбор
                ->where('rules.0.state', 'matched')
                ->where('rules.0.stop_processing', true)
                // Второе до разбора не дошло — это и есть ответ «почему не пришло клиенту»
                ->where('rules.1.state', 'not_reached')
                ->has('deliveries', 1)
                ->where('deliveries.0.recipient', 'dir@x.ru')
            );
    }

    #[Test]
    #[TestDox('Трасса читаема, когда правил нет вовсе')]
    public function trace_without_rules(): void
    {
        Notification::fake();

        $result = app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: 'orders.shipped',
            clientUserId: $this->partner->id,
            companyId: $this->company->id,
            data: ['amount' => 100],
        ));

        $this->actingAs($this->manager)
            ->get("/crm/notifications/signals/{$result['signal_uuid']}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rules', 0)
                ->has('deliveries', 0)
            );
    }

    #[Test]
    #[TestDox('Менеджер не видит журнал чужих клиентов')]
    public function journal_is_scoped(): void
    {
        Notification::fake();

        $foreign = User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);

        NotificationDelivery::create([
            'signal_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'event_key' => 'orders.shipped',
            'client_user_id' => $foreign->id,
            'channel' => 'email',
            'recipient' => 'foreign@x.ru',
            'recipient_kind' => 'email',
            'status' => NotificationDelivery::STATUS_SENT,
        ]);

        $this->actingAs($this->manager)
            ->get('/crm/notifications/journal')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('deliveries.data', 0));
    }

    #[Test]
    #[TestDox('Трасса чужого клиента отдаёт 404')]
    public function foreign_trace_is_hidden(): void
    {
        $foreign = User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);

        $signal = NotificationSignal::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'event_key' => 'orders.shipped',
            'client_user_id' => $foreign->id,
            'data' => [],
            'mode' => 'live',
        ]);

        $this->actingAs($this->manager)
            ->get("/crm/notifications/signals/{$signal->uuid}")
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Старые записи журнала вычищаются, свежие остаются')]
    public function retention_prunes_old_records(): void
    {
        config([
            'notification_pulse.retention.signals_days' => 30,
            'notification_pulse.retention.deliveries_days' => 365,
        ]);

        // created_at не в fillable — проставляем запросом, иначе все записи
        // получат текущее время и ретенцию проверить не на чем
        $make = function (string $uuid, $createdAt) {
            $signal = NotificationSignal::create([
                'uuid' => $uuid,
                'event_key' => 'orders.shipped',
                'data' => [],
                'mode' => 'live',
            ]);
            NotificationSignal::whereKey($signal->id)->update(['created_at' => $createdAt]);

            $delivery = NotificationDelivery::create([
                'signal_uuid' => $uuid,
                'event_key' => 'orders.shipped',
                'channel' => 'email',
                'recipient' => $uuid.'@x.ru',
                'recipient_kind' => 'email',
                'status' => NotificationDelivery::STATUS_SENT,
            ]);
            NotificationDelivery::whereKey($delivery->id)->update(['created_at' => $createdAt]);
        };

        $make('old-signal', now()->subDays(40));
        $make('very-old', now()->subDays(400));
        $make('fresh', now());

        $this->artisan('model:prune', ['--model' => [
            NotificationSignal::class,
            NotificationDelivery::class,
        ]])->assertSuccessful();

        // Сигналы держим 30 дней
        $this->assertSame(1, NotificationSignal::count());
        $this->assertNotNull(NotificationSignal::where('uuid', 'fresh')->first());

        // Доставки — год: сорокадневная ещё жива, четырёхсотдневная нет
        $this->assertSame(2, NotificationDelivery::count());
        $this->assertNull(NotificationDelivery::where('signal_uuid', 'very-old')->first());
    }
}
