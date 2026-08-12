<?php

namespace Tests\Feature\Crm;

use App\Models\CrmComment;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

class CommentsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $managerA;

    private User $managerB;

    private PersonalManager $profileA;

    private PersonalManager $profileB;

    private User $clientA;

    private User $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->managerA = User::factory()->create();
        $this->managerA->assignRole('sales-manager');
        $this->profileA = PersonalManager::factory()->create(['user_id' => $this->managerA->id]);

        $this->managerB = User::factory()->create();
        $this->managerB->assignRole('sales-manager');
        $this->profileB = PersonalManager::factory()->create(['user_id' => $this->managerB->id]);

        $this->clientA = User::factory()->create(['personal_manager_id' => $this->profileA->id]);
        $this->clientB = User::factory()->create(['personal_manager_id' => $this->profileB->id]);
    }

    private function salesHead(): User
    {
        $user = User::factory()->create();
        $user->assignRole('sales-head');

        return $user;
    }

    #[Test]
    public function manager_comments_on_own_client(): void
    {
        $this->actingAs($this->managerA)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->clientA->id,
                'body' => 'Договорились созвониться в пятницу',
            ])
            ->assertCreated()
            ->assertJsonPath('type', 'comment')
            ->assertJsonPath('author.name', $this->managerA->name)
            ->assertJsonPath('entity.type', 'client');

        $this->assertDatabaseHas('crm_comments', [
            'commentable_type' => User::class,
            'commentable_id' => $this->clientA->id,
            'client_user_id' => $this->clientA->id,
            'user_id' => $this->managerA->id,
        ]);
    }

    #[Test]
    public function client_is_resolved_from_order_and_shipment(): void
    {
        $order = Order::factory()->create(['user_id' => $this->clientA->id]);
        $shipment = Shipment::factory()->create(['user_id' => $this->clientA->id]);

        $this->actingAs($this->managerA)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'order',
                'entity_id' => $order->id,
                'body' => 'Клиент просит отгрузить частями',
            ])
            ->assertCreated();

        $this->actingAs($this->managerA)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'shipment',
                'entity_id' => $shipment->id,
                'body' => 'Документы подписаны',
            ])
            ->assertCreated();

        // Денормализация — смысл всей ленты: обе записи знают своего клиента,
        // хотя оставлены на разных сущностях.
        $this->assertSame(2, CrmComment::query()->forClient($this->clientA->id)->count());
    }

    #[Test]
    public function manager_cannot_comment_on_foreign_client(): void
    {
        $this->actingAs($this->managerA)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->clientB->id,
                'body' => 'Попытка написать в чужую карточку',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('crm_comments', 0);
    }

    #[Test]
    public function manager_cannot_comment_on_foreign_clients_order(): void
    {
        $order = Order::factory()->create(['user_id' => $this->clientB->id]);

        $this->actingAs($this->managerA)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'order',
                'entity_id' => $order->id,
                'body' => 'Чужой заказ',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function unknown_entity_type_is_rejected_by_validation(): void
    {
        $this->actingAs($this->managerA)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'App\\Models\\Product',
                'entity_id' => 1,
                'body' => 'Произвольный класс',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('entity_type');
    }

    #[Test]
    public function order_without_user_is_commentable_only_by_sales_head(): void
    {
        $order = Order::factory()->create(['user_id' => null]);

        $this->actingAs($this->managerA)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'order',
                'entity_id' => $order->id,
                'body' => 'Партнёрский заказ из 1С',
            ])
            ->assertNotFound();

        $head = $this->salesHead();

        $this->actingAs($head)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'order',
                'entity_id' => $order->id,
                'body' => 'Партнёрский заказ из 1С',
            ])
            ->assertCreated()
            ->assertJsonPath('entity.type', 'order');

        // В ленту такой комментарий не идёт — сводить его не к кому.
        $this->assertDatabaseHas('crm_comments', [
            'commentable_id' => $order->id,
            'client_user_id' => null,
        ]);
    }

    #[Test]
    public function author_can_edit_own_comment_and_others_cannot(): void
    {
        $comment = CrmComment::factory()->on($this->clientA)->by($this->managerA)->create();

        $this->actingAs($this->managerA)
            ->patchJson(route('crm.comments.update', $comment), ['body' => 'Уточнение'])
            ->assertOk()
            ->assertJsonPath('body', 'Уточнение');

        // Менеджер B клиента A не видит вовсе — 404, а не 403.
        $this->actingAs($this->managerB)
            ->patchJson(route('crm.comments.update', $comment), ['body' => 'Чужая правка'])
            ->assertNotFound();
    }

    #[Test]
    public function colleague_on_same_client_gets_403_not_404(): void
    {
        // Комментарий коллеги по клиенту, который менеджеру A виден.
        $comment = CrmComment::factory()->on($this->clientA)->by($this->managerB)->create();

        $this->actingAs($this->managerA)
            ->patchJson(route('crm.comments.update', $comment), ['body' => 'Правка чужого'])
            ->assertForbidden();

        $this->actingAs($this->managerA)
            ->deleteJson(route('crm.comments.destroy', $comment))
            ->assertForbidden();
    }

    #[Test]
    public function sales_head_can_delete_any_comment_in_department(): void
    {
        $comment = CrmComment::factory()->on($this->clientA)->by($this->managerA)->create();

        $this->actingAs($this->salesHead())
            ->deleteJson(route('crm.comments.destroy', $comment))
            ->assertOk();

        $this->assertSoftDeleted('crm_comments', ['id' => $comment->id]);
    }

    #[Test]
    public function entity_thread_lists_only_comments_of_that_entity(): void
    {
        $order = Order::factory()->create(['user_id' => $this->clientA->id]);

        CrmComment::factory()->on($this->clientA)->by($this->managerA)->create();
        CrmComment::factory()->count(2)->on($order)->by($this->managerA)->create();

        $this->actingAs($this->managerA)
            ->getJson(route('crm.comments.index', ['entity_type' => 'order', 'entity_id' => $order->id]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function crm_employee_without_comment_permission_gets_403(): void
    {
        // Есть доступ в CRM, но нет прав на комментарии — режет `permission:`,
        // а не входной middleware.
        $role = Role::create(['name' => 'crm-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo('crm-dashboard.view');

        $employee = User::factory()->create();
        $employee->assignRole($role);

        $this->actingAs($employee)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->clientA->id,
                'body' => 'Не моё дело',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function user_without_crm_access_is_bounced_out_of_crm(): void
    {
        $catalogist = User::factory()->create();
        $catalogist->assignRole('catalogist');

        // EnsureUserIsCrm уводит на главную — до проверки прав дело не доходит.
        $this->actingAs($catalogist)
            ->post(route('crm.comments.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->clientA->id,
                'body' => 'Не моё дело',
            ])
            ->assertRedirect('/');

        $this->assertDatabaseCount('crm_comments', 0);
    }
}
