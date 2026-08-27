<?php

namespace Tests\Feature\Crm\Notifications;

use App\Models\Contact;
use App\Models\NotificationPreference;
use App\Models\NotificationSuppression;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Матрица уведомлений в карточке партнёра.
 *
 * Ключевое свойство: строка появляется только при отклонении от умолчания,
 * а возврат к умолчанию её удаляет. Иначе изменение умолчания в конфиге
 * не дошло бы до тех, кто его не менял.
 */
class NotificationMatrixTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);
    }

    #[Test]
    public function матрица_отдаёт_все_типы_с_умолчаниями(): void
    {
        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.notifications.index', $this->client))
            ->assertOk();

        $rows = collect($response->json('rows'))->keyBy('key');

        $this->assertCount(count(config('mail_occasions')), $rows);
        // Клиентские уведомления выключены умолчанием: начинаем с тишины
        // и настраиваем каждому партнёру индивидуально.
        $this->assertFalse($rows['orders.created']['enabled']);
        $this->assertFalse($rows['orders.created']['overridden']);
        // Внутренние остались включёнными — отдел продаж не ослепляем.
        $this->assertTrue($rows['finance.overdue_started']['enabled']);
        $this->assertSame('login', $rows['orders.created']['destinations'][0]['type']);
        $this->assertSame('status', $rows['orders.status_changed']['subtype']['field']);
        $this->assertSame('document_type', $rows['documents.published']['subtype']['field']);
    }

    #[Test]
    public function отклонение_создаёт_строку_только_для_изменённого_типа(): void
    {
        $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'orders.created',
                'is_enabled' => false,
                'destinations' => [],
            ])
            ->assertOk();

        $this->assertSame(1, NotificationPreference::query()->count());
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->client->id,
            'occasion_key' => 'orders.created',
            'is_enabled' => false,
        ]);
    }

    #[Test]
    public function возврат_к_умолчанию_удаляет_строку(): void
    {
        NotificationPreference::query()->create([
            'user_id' => $this->client->id,
            'occasion_key' => 'orders.created',
            'is_enabled' => true,
            'destinations' => [['type' => 'login']],
        ]);

        // Возврат к умолчанию — теперь это «выключено».
        $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'orders.created',
                'is_enabled' => false,
                'destinations' => [['type' => 'login']],
            ])
            ->assertOk();

        // Копию умолчания не пишем: иначе правка конфига не дойдёт до партнёра.
        $this->assertSame(0, NotificationPreference::query()->count());
    }

    #[Test]
    public function чужой_партнёр_отвечает_404(): void
    {
        $foreign = User::factory()->create();

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.notifications.index', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function чужой_контакт_в_адресаты_не_попадает(): void
    {
        $foreignContact = Contact::factory()->create([
            'client_user_id' => User::factory()->create()->id,
            'email' => 'foreign@example.com',
        ]);

        $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'documents.published',
                'is_enabled' => true,
                'destinations' => [['type' => 'contact', 'contact_id' => $foreignContact->id]],
            ])
            ->assertOk();

        $saved = NotificationPreference::query()->first();

        // Через настройку одного партнёра не должны утекать адреса другого.
        $this->assertSame([], $saved?->destinations ?? []);
    }

    #[Test]
    public function неизвестный_тип_уведомления_отвергается_по_русски(): void
    {
        $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'orders.nonexistent',
                'is_enabled' => true,
                'destinations' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.occasion_key.0', 'Неизвестный тип уведомления.');
    }

    #[Test]
    public function менеджер_без_права_на_правку_не_меняет_настройки(): void
    {
        // Право приходит от роли, а не от пользователя, — снимаем у роли.
        \Spatie\Permission\Models\Role::findByName('sales-manager')->revokePermissionTo('crm-clients.edit');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'orders.created',
                'is_enabled' => false,
                'destinations' => [],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function удалённого_менеджера_можно_вернуть(): void
    {
        // Умолчание финансовых уведомлений — персональный менеджер. Если его
        // убрать, вернуть должно быть чем: в выборе адресата такой пункт есть.
        $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'finance.payment_due_soon',
                'is_enabled' => true,
                'destinations' => [],
            ])
            ->assertOk();

        $response = $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'finance.payment_due_soon',
                'is_enabled' => true,
                'destinations' => [['type' => 'manager']],
            ])
            ->assertOk();

        $row = collect($response->json('rows'))->firstWhere('key', 'finance.payment_due_soon');

        $this->assertSame('manager', $row['destinations'][0]['type']);
        $this->assertSame('Персональному менеджеру', $row['destinations'][0]['label']);

        // Вернулись к умолчанию — строка отклонения исчезла.
        $this->assertSame(0, NotificationPreference::query()->count());
    }

    #[Test]
    public function матрица_показывает_рассылки_и_письма_менеджера(): void
    {
        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.notifications.index', $this->client))
            ->assertOk();

        $extras = collect($response->json('extras'))->keyBy('key');

        $this->assertTrue($extras['extra.campaigns']['toggleable']);
        // Письма менеджера отключить нельзя — это человек пишет человеку.
        // Строка нужна ради полноты картины, а не ради настройки.
        $this->assertFalse($extras['extra.manager']['toggleable']);
        $this->assertTrue($extras['extra.manager']['enabled']);
    }

    #[Test]
    public function отказ_от_рассылок_гасит_только_рекламу(): void
    {
        $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.marketing', $this->client), ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('extras.0.enabled', false);

        // Реклама погашена, а уведомления о заказах продолжают работать.
        $this->assertTrue(NotificationSuppression::blocks($this->client->email, 'campaigns.promo'));
        $this->assertFalse(NotificationSuppression::blocks($this->client->email, 'orders.status_changed'));
    }

    #[Test]
    public function набор_подтипов_сохраняется(): void
    {
        $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'orders.status_changed',
                'is_enabled' => true,
                'destinations' => [['type' => 'login']],
                'options' => ['subtypes' => ['ready_for_shipment']],
            ])
            ->assertOk();

        $this->assertSame(
            ['subtypes' => ['ready_for_shipment']],
            NotificationPreference::query()->first()->options,
        );
    }
}
