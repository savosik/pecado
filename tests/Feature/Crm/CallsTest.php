<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\CallResult;
use App\Models\CrmCall;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Журнал звонков.
 *
 * Телефонии нет — записи заводятся руками, но поля под АТС уже есть, и защита
 * от дублей по (provider, external_id) проверяется сразу: чинить её потом,
 * когда вебхук начнёт двоить звонки на проде, будет поздно.
 */
class CallsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $card;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $this->card->id]);
    }

    private function foreignClient(): User
    {
        return User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    #[Test]
    public function manager_logs_call_and_it_appears_in_client_timeline(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.calls.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'direction' => 'outgoing',
                'result' => 'talked',
                'summary' => 'Согласовали отсрочку 14 дней',
            ])
            ->assertCreated()
            ->assertJsonPath('call.result_label', 'Поговорили')
            ->assertJsonPath('call.summary', 'Согласовали отсрочку 14 дней')
            ->assertJsonPath('follow_up', null);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['call']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'call')
            ->assertJsonPath('data.0.excerpt', 'Согласовали отсрочку 14 дней');
    }

    #[Test]
    public function call_with_follow_up_creates_task_in_one_transaction(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson(route('crm.calls.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'result' => 'callback',
                'summary' => 'Просил перезвонить в среду',
                'follow_up' => [
                    'title' => 'Перезвонить по КП',
                    'due_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                ],
            ])
            ->assertCreated();

        $taskId = $response->json('follow_up.id');
        $this->assertNotNull($taskId);

        $call = CrmCall::query()->latest('id')->firstOrFail();
        $this->assertSame($taskId, $call->follow_up_task_id);

        $task = CrmTask::findOrFail($taskId);
        // Задача наследует привязку звонка — искать клиента заново незачем.
        $this->assertSame($this->client->id, $task->client_user_id);
        $this->assertSame($this->manager->id, $task->assignee_id);
    }

    #[Test]
    public function follow_up_without_title_is_rejected_in_russian(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson(route('crm.calls.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'follow_up' => ['due_at' => now()->addDay()->format('Y-m-d H:i:s')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('follow_up.title');

        // Сообщение на русском — правило проекта распространяется и на валидацию.
        $this->assertSame(
            'Введите, что нужно сделать по итогам звонка.',
            $response->json('errors')['follow_up.title'][0],
        );

        $this->assertDatabaseCount('crm_calls', 0);
    }

    #[Test]
    public function call_store_requires_crm_calls_create(): void
    {
        $reader = User::factory()->create();
        $reader->givePermissionTo(['crm-clients.view', 'crm-calls.view']);
        $card = PersonalManager::factory()->create(['user_id' => $reader->id]);
        $this->client->update(['personal_manager_id' => $card->id]);

        $this->actingAs($reader->fresh())
            ->postJson(route('crm.calls.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function manager_cannot_log_call_on_foreign_client(): void
    {
        $foreign = $this->foreignClient();

        // 404, а не 403: существование чужого клиента не подтверждаем.
        $this->actingAs($this->manager)
            ->postJson(route('crm.calls.store'), [
                'entity_type' => 'client',
                'entity_id' => $foreign->id,
                'result' => 'talked',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('crm_calls', 0);
    }

    #[Test]
    public function manager_cannot_list_calls_of_foreign_client(): void
    {
        $foreign = $this->foreignClient();
        CrmCall::factory()->on($foreign)->create();

        $this->actingAs($this->manager)
            ->getJson(route('crm.calls.index', ['entity_type' => 'client', 'entity_id' => $foreign->id]))
            ->assertNotFound();
    }

    #[Test]
    public function foreign_call_stays_out_of_own_client_list(): void
    {
        $foreign = $this->foreignClient();
        CrmCall::factory()->on($foreign)->create(['summary' => 'Чужой разговор']);
        CrmCall::factory()->on($this->client)->by($this->manager)->create(['summary' => 'Свой разговор']);

        $summaries = collect(
            $this->actingAs($this->manager)
                ->getJson(route('crm.calls.index', ['entity_type' => 'client', 'entity_id' => $this->client->id]))
                ->assertOk()
                ->json('data')
        )->pluck('summary')->all();

        $this->assertSame(['Свой разговор'], $summaries);
    }

    #[Test]
    public function call_client_is_resolved_from_related_order(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.calls.store'), [
                'entity_type' => 'order',
                'entity_id' => $order->id,
                'result' => 'talked',
                'summary' => 'Уточнили состав заказа',
            ])
            ->assertCreated();

        $call = CrmCall::query()->latest('id')->firstOrFail();
        // Звонок по заказу — это звонок по его клиенту, и в ленте он должен быть.
        $this->assertSame($this->client->id, $call->client_user_id);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['call']]))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function manager_edits_own_call_but_not_foreign_one(): void
    {
        $own = CrmCall::factory()->on($this->client)->by($this->manager)->create();

        $this->actingAs($this->manager)
            ->patchJson(route('crm.calls.update', $own), ['summary' => 'Уточнил формулировку'])
            ->assertOk()
            ->assertJsonPath('summary', 'Уточнил формулировку');

        $colleague = User::factory()->create();
        $colleague->assignRole('sales-manager');
        $foreignCall = CrmCall::factory()->on($this->client)->by($colleague)->create();

        // Клиент общий, но чужую запись рядовой менеджер не правит.
        $this->actingAs($this->manager)
            ->patchJson(route('crm.calls.update', $foreignCall), ['summary' => 'Перепишу за коллегу'])
            ->assertForbidden();
    }

    #[Test]
    public function deleting_call_keeps_follow_up_task(): void
    {
        $task = CrmTask::factory()->by($this->manager)->assignedTo($this->manager)->on($this->client)->create();
        $call = CrmCall::factory()->on($this->client)->by($this->manager)
            ->create(['follow_up_task_id' => $task->id]);

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.calls.destroy', $call))
            ->assertNoContent();

        // Звонок ушёл, но поставленная по нему работа остаётся.
        $this->assertSoftDeleted('crm_calls', ['id' => $call->id]);
        $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'deleted_at' => null]);
    }

    #[Test]
    public function provider_and_external_id_pair_is_unique(): void
    {
        CrmCall::factory()->on($this->client)->create([
            'provider' => 'mango',
            'external_id' => 'call-777',
        ]);

        // Задел под вебхук АТС: повторная доставка не должна двоить звонок.
        $this->expectException(\Illuminate\Database\QueryException::class);

        CrmCall::factory()->on($this->client)->create([
            'provider' => 'mango',
            'external_id' => 'call-777',
        ]);
    }

    #[Test]
    public function manual_calls_do_not_collide_on_empty_external_id(): void
    {
        // У ручных записей external_id пуст — уникальный индекс не должен мешать
        // записывать сколько угодно звонков подряд.
        CrmCall::factory()->on($this->client)->count(3)->create();

        $this->assertDatabaseCount('crm_calls', 3);
    }

    #[Test]
    public function no_answer_call_is_recorded_without_summary(): void
    {
        // Попытка — тоже работа с клиентом: требовать текст на «не ответил»
        // означало бы отучить людей фиксировать звонки вовсе.
        $this->actingAs($this->manager)
            ->postJson(route('crm.calls.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'result' => CallResult::NO_ANSWER->value,
            ])
            ->assertCreated()
            ->assertJsonPath('call.result_label', 'Не ответил')
            ->assertJsonPath('call.summary', null);
    }
}
