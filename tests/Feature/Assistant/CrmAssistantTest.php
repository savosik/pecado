<?php

namespace Tests\Feature\Assistant;

use App\Models\ChatMessage;
use App\Models\ClientAssistantNote;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Assistant\ThreadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use PHPUnit\Framework\Attributes\Test;

/**
 * Вкладка «Помощник» в карточке партнёра: свой клиент — читаем, чужой — 404,
 * без права — 403; ничего не редактируется.
 */
class CrmAssistantTest extends AssistantTestCase
{
    private User $manager;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create(['name' => 'Менеджер']);
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id, 'name' => 'Менеджер']);
        $this->client->forceFill(['personal_manager_id' => $card->id])->save();

        // Менеджер без права «отдел»: видит только своих партнёров.
        $this->stranger = User::factory()->create(['name' => 'Другой менеджер']);
        $this->stranger->givePermissionTo(['crm-clients.view', 'crm-agent-usage.view']);
        PersonalManager::factory()->create(['user_id' => $this->stranger->id, 'name' => 'Другой']);
    }

    #[Test]
    public function менеджер_читает_переписку_своего_клиента(): void
    {
        $thread = app(ThreadService::class)->open($this->client, ['type' => 'product', 'id' => '1', 'title' => 'LE-13']);
        ChatMessage::create(['thread_id' => $thread->id, 'role' => 'user', 'content' => [['type' => 'text', 'text' => 'Сколько стоит?']], 'text' => 'Сколько стоит?', 'status' => 'done']);
        ChatMessage::create(['thread_id' => $thread->id, 'role' => 'assistant', 'content' => [['type' => 'mcp_tool_use', 'id' => 't1', 'name' => 'client-prices', 'server_name' => 'pecado', 'input' => []], ['type' => 'text', 'text' => '1 200 ₽']], 'text' => '1 200 ₽', 'status' => 'done', 'cost' => 0.01]);
        ClientAssistantNote::create(['user_id' => $this->client->id, 'content' => 'Берёт по средам.']);

        $index = $this->actingAs($this->manager)->getJson("/crm/partners/{$this->client->id}/assistant")->assertOk()->json();
        $this->assertCount(1, $index['threads']);
        $this->assertSame('product', $index['threads'][0]['page']['type']);
        $this->assertSame('Берёт по средам.', $index['note']['content']);

        $detail = $this->actingAs($this->manager)->getJson("/crm/partners/{$this->client->id}/assistant/{$thread->id}")->assertOk()->json();
        $this->assertCount(2, $detail['messages']);
        $this->assertSame(['client-prices'], $detail['messages'][1]['tools']);
        $this->assertSame(0.01, $detail['messages'][1]['cost']);
    }

    #[Test]
    public function чужой_партнёр_404_а_без_права_403(): void
    {
        $thread = app(ThreadService::class)->open($this->client);

        $this->actingAs($this->stranger)->getJson("/crm/partners/{$this->client->id}/assistant")->assertNotFound();
        $this->actingAs($this->stranger)->getJson("/crm/partners/{$this->client->id}/assistant/{$thread->id}")->assertNotFound();

        $this->manager->revokePermissionTo('crm-agent-usage.view');
        $this->manager->roles()->detach();
        $this->manager->givePermissionTo('crm-clients.view');
        $this->actingAs($this->manager)->getJson("/crm/partners/{$this->client->id}/assistant")->assertForbidden();
    }

    #[Test]
    public function роп_видит_всех_а_тред_чужого_клиента_под_своим_партнёром_не_открывается(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');
        $other = User::factory()->create(['erp_id' => (string) \Illuminate\Support\Str::uuid()]);
        \App\Models\Company::factory()->create(['user_id' => $other->id, 'tax_id' => '7727563778', 'is_default' => true]);
        $foreign = app(ThreadService::class)->open($other);

        $this->actingAs($head)->getJson("/crm/partners/{$this->client->id}/assistant")->assertOk();
        $this->actingAs($head)->getJson("/crm/partners/{$this->client->id}/assistant/{$foreign->id}")->assertNotFound();
    }
}
