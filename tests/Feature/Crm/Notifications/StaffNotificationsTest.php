<?php

namespace Tests\Feature\Crm\Notifications;

use App\Models\NotificationPreference;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Notifications\StaffNotifications;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Что получает сотрудник.
 *
 * Две поверхности над одной настройкой: «Мои уведомления» для себя и та же
 * матрица в «Команде» для руководителя.
 */
class StaffNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $head;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create([
            'user_id' => $this->manager->id,
            'email' => 'manager@pecado.ru',
        ]);

        $this->head = User::factory()->create(['email' => 'head@pecado.ru']);
        $this->head->assignRole('sales-head');
    }

    #[Test]
    public function сотрудник_видит_свои_уведомления(): void
    {
        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.my-notifications.data'))
            ->assertOk();

        $keys = array_column($response->json('rows'), 'key');

        $this->assertContains('staff.task_assigned', $keys);
        $this->assertContains('staff.order_created', $keys);
        // Клиентские поводы сюда не попадают: у партнёра выбирают адресатов,
        // у сотрудника адресат один — он сам.
        $this->assertNotContains('orders.status_changed', $keys);
    }

    #[Test]
    public function сотрудник_отключает_себе_уведомление(): void
    {
        $this->actingAs($this->manager)
            ->patchJson(route('crm.my-notifications.update'), [
                'occasion_key' => 'staff.task_assigned',
                'is_enabled' => false,
            ])
            ->assertOk();

        $this->assertFalse(app(StaffNotifications::class)->wants($this->manager, 'staff.task_assigned'));
    }

    #[Test]
    public function возврат_к_умолчанию_удаляет_строку(): void
    {
        NotificationPreference::query()->create([
            'user_id' => $this->manager->id,
            'occasion_key' => 'staff.task_assigned',
            'is_enabled' => false,
        ]);

        $this->actingAs($this->manager)
            ->patchJson(route('crm.my-notifications.update'), [
                'occasion_key' => 'staff.task_assigned',
                'is_enabled' => true,
            ])
            ->assertOk();

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    #[Test]
    public function руководитель_правит_настройки_сотрудника(): void
    {
        $this->actingAs($this->head)
            ->patchJson(route('crm.my-notifications.update'), [
                'manager' => $this->card->id,
                'occasion_key' => 'staff.shortage_digest',
                'is_enabled' => false,
            ])
            ->assertOk();

        $this->assertFalse(app(StaffNotifications::class)->wants($this->manager, 'staff.shortage_digest'));
    }

    #[Test]
    public function без_права_на_команду_чужие_настройки_не_меняются(): void
    {
        // Параметр просто игнорируется, и человек настраивает себя: так
        // надёжнее, чем полагаться на то, что интерфейс не пришлёт лишнего.
        $this->actingAs($this->manager)
            ->patchJson(route('crm.my-notifications.update'), [
                'manager' => $this->card->id,
                'occasion_key' => 'staff.weekly_report',
                'is_enabled' => false,
            ])
            ->assertOk();

        $row = NotificationPreference::query()->firstOrFail();

        $this->assertSame($this->manager->id, $row->user_id);
    }

    #[Test]
    public function неизвестный_тип_отвергается_по_русски(): void
    {
        $this->actingAs($this->manager)
            ->patchJson(route('crm.my-notifications.update'), [
                'occasion_key' => 'staff.nonexistent',
                'is_enabled' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.occasion_key.0', 'Неизвестный тип уведомления.');
    }

    #[Test]
    public function общий_ящик_без_учётки_получает_всегда(): void
    {
        // Фолбэк-адрес отдела настраивать некому: учётки за ним нет.
        $this->assertTrue(
            app(StaffNotifications::class)->wantsByEmail('sales@pecado.ru', 'staff.order_created'),
        );
    }
}
