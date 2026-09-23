<?php

namespace Tests\Feature\Assistant;

use App\Models\ChatEvent;
use App\Models\ChatMessage;
use App\Models\ClientAgentCall;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Assistant\AssistantTokenIssuer;
use App\Services\Assistant\ThreadService;
use App\Services\Client\Api\Usage\UsageRecorder;
use Database\Seeders\RolesAndPermissionsSeeder;
use PHPUnit\Framework\Attributes\Test;

/**
 * Воронка помощника на экране «ИИ-агенты клиентов»: по клиентам, не по событиям.
 */
class FunnelTest extends AssistantTestCase
{
    #[Test]
    public function воронка_считает_клиентов_стоимость_и_эскалации(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $head = User::factory()->create();
        $head->assignRole('sales-head');
        PersonalManager::factory()->create(['user_id' => $head->id, 'name' => 'РОП']);

        $threads = app(ThreadService::class);
        $second = User::factory()->create(['erp_id' => (string) \Illuminate\Support\Str::uuid()]);
        \App\Models\Company::factory()->create(['user_id' => $second->id, 'tax_id' => '7727563778', 'is_default' => true]);

        // Первый клиент: видел, открыл, написал, подтвердил; два ответа модели; вопрос менеджеру.
        $t1 = $threads->open($this->client);
        $token = app(AssistantTokenIssuer::class)->forThread($t1);
        foreach ([ChatEvent::SHOWN, ChatEvent::SHOWN, ChatEvent::BUBBLE_SHOWN, ChatEvent::OPENED, ChatEvent::FIRST_MESSAGE, ChatEvent::CONFIRMED_ACTION] as $event) {
            $threads->event($this->client, $event, $t1, 'product', $event === ChatEvent::BUBBLE_SHOWN ? 'product.in_stock' : null);
        }
        $threads->event($this->client, ChatEvent::BUBBLE_CLICKED, $t1, 'product', 'product.in_stock');
        ChatMessage::create(['thread_id' => $t1->id, 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'a']], 'text' => 'a', 'status' => 'done', 'cost' => 0.02]);
        ChatMessage::create(['thread_id' => $t1->id, 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'b']], 'text' => 'b', 'status' => 'done', 'cost' => 0.03]);
        $t1->forceFill(['cost' => 0.05])->save();
        ClientAgentCall::create(['kind' => 'mcp_tool', 'user_id' => $this->client->id, 'token_id' => $token->id, 'agent' => UsageRecorder::AGENT_WEB_ASSISTANT, 'tool' => 'client-ask-manager', 'operation' => 'questions.create', 'mutating' => true, 'ok' => true, 'created_at' => now()]);
        ClientAgentCall::create(['kind' => 'mcp_tool', 'user_id' => $this->client->id, 'token_id' => $token->id, 'agent' => UsageRecorder::AGENT_WEB_ASSISTANT, 'tool' => 'client-create-order', 'operation' => 'orders.create', 'mutating' => true, 'ok' => true, 'created_at' => now()]);

        // Второй клиент: только видел иконку.
        $threads->event($second, ChatEvent::SHOWN, null, 'catalog');

        // Токен помощника у клиента активен, но это не «ключ, выданный в кабинете».
        $this->assertSame(1, \App\Models\ApiToken::where('user_id', $this->client->id)->where('kind', 'assistant')->where('is_active', true)->count());

        $page = $this->actingAs($head)->get('/crm/agent-usage?scope=department')->assertOk();

        $page->assertInertia(fn ($p) => $p
            ->where('summary.partners_with_tokens', 0)
            ->where('assistant.funnel.0.value', 2)
            ->where('assistant.funnel.1.value', 1)
            ->where('assistant.funnel.2.value', 1)
            ->where('assistant.funnel.3.value', 1)
            ->where('assistant.threads', 1)
            ->where('assistant.turns', 2)
            ->where('assistant.orders', 1)
            ->where('assistant.escalated', 1)
            ->where('assistant.escalation_share', 100)
            ->where('assistant.cost_usd', 0.05)
            ->where('assistant.bubbles.0.key', 'product.in_stock')
            ->where('assistant.bubbles.0.shown', 1)
            ->where('assistant.bubbles.0.clicked', 1));
    }
}
