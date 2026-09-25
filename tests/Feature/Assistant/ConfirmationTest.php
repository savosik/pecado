<?php

namespace Tests\Feature\Assistant;

use App\Jobs\Assistant\RunAssistantTurn;
use App\Models\ApiToken;
use App\Models\ChatConfirmation;
use App\Models\ChatEvent;
use App\Models\ChatThread;
use App\Services\Assistant\AssistantTokenIssuer;
use App\Services\Assistant\ThreadService;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;

/**
 * Необратимая операция из чата выполняется только по кнопке клиента:
 * первый вызов — карточка, после «Подтвердить» тот же вызов проходит,
 * личный токен воротами не задерживается.
 */
class ConfirmationTest extends AssistantTestCase
{
    private ChatThread $thread;

    private ApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thread = app(ThreadService::class)->open($this->client);
        $this->token = app(AssistantTokenIssuer::class)->forThread($this->thread);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function mcpCall(ApiToken $token, string $tool, array $arguments): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/mcp/client', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.$token->token, 'Accept' => 'application/json, text/event-stream']);
    }

    #[Test]
    public function вопрос_менеджеру_из_чата_ждёт_кнопку_а_после_подтверждения_выполняется(): void
    {
        Bus::fake([RunAssistantTurn::class]);
        $args = ['subject' => 'Объединить заказы', 'body' => 'Прошу отправить заказы 1 и 2 одной посылкой.'];

        $first = $this->mcpCall($this->token, 'client-call', ['operation' => 'questions.create', 'arguments' => $args]);
        $first->assertOk();
        $this->assertStringContainsString('confirmation_required', json_encode($first->json(), JSON_UNESCAPED_UNICODE));

        $confirmation = ChatConfirmation::query()->where('thread_id', $this->thread->id)->first();
        $this->assertNotNull($confirmation);
        $this->assertSame('pending', $confirmation->status);
        $this->assertSame('questions.create', $confirmation->operation);
        $this->assertSame(0, \App\Models\UserQuestion::query()->count(), 'до кнопки операция не выполняется');

        // Повтор до кнопки — та же карточка, вторая не создаётся.
        $this->mcpCall($this->token, 'client-call', ['operation' => 'questions.create', 'arguments' => $args])->assertOk();
        $this->assertSame(1, ChatConfirmation::query()->count());

        // Кнопка «Подтвердить» в кабинете.
        $this->actingAs($this->client)
            ->postJson('/cabinet/assistant/confirmations/'.$confirmation->id, ['approve' => true])
            ->assertOk()
            ->assertJsonPath('busy', true);

        $this->assertSame('approved', $confirmation->refresh()->status);
        $this->assertSame(1, ChatEvent::query()->where('event', ChatEvent::CONFIRMED_ACTION)->count());
        $this->assertSame('confirmation', $this->thread->messages()->where('kind', 'confirmation')->first()?->kind);

        // Модель повторяет вызов с теми же аргументами — теперь проходит.
        $second = $this->mcpCall($this->token, 'client-call', ['operation' => 'questions.create', 'arguments' => $args]);
        $second->assertOk();
        $this->assertStringNotContainsString('confirmation_required', json_encode($second->json(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, \App\Models\UserQuestion::query()->count());
        $this->assertSame('used', $confirmation->refresh()->status);
    }

    #[Test]
    public function изменённые_аргументы_требуют_нового_подтверждения(): void
    {
        $this->mcpCall($this->token, 'client-call', ['operation' => 'questions.create', 'arguments' => ['subject' => 'Объединить заказы', 'body' => 'Прошу отправить заказы одной посылкой, первый вариант.']])->assertOk();
        $confirmation = ChatConfirmation::query()->first();
        $confirmation->forceFill(['status' => ChatConfirmation::STATUS_APPROVED, 'decided_at' => now()])->save();

        $this->mcpCall($this->token, 'client-call', ['operation' => 'questions.create', 'arguments' => ['subject' => 'Объединить заказы', 'body' => 'Прошу отправить заказы одной посылкой, другой вариант.']])->assertOk();

        $this->assertSame(2, ChatConfirmation::query()->count());
        $this->assertSame(0, \App\Models\UserQuestion::query()->count());
    }

    #[Test]
    public function отказ_клиента_не_выполняет_операцию(): void
    {
        Bus::fake([RunAssistantTurn::class]);
        $this->mcpCall($this->token, 'client-call', ['operation' => 'questions.create', 'arguments' => ['subject' => 'Объединить заказы', 'body' => 'Прошу отправить заказы 1 и 2 одной посылкой.']])->assertOk();
        $confirmation = ChatConfirmation::query()->first();

        $this->actingAs($this->client)
            ->postJson('/cabinet/assistant/confirmations/'.$confirmation->id, ['approve' => false])
            ->assertOk();

        $this->assertSame('declined', $confirmation->refresh()->status);
        $this->mcpCall($this->token, 'client-call', ['operation' => 'questions.create', 'arguments' => ['subject' => 'Объединить заказы', 'body' => 'Прошу отправить заказы 1 и 2 одной посылкой.']])->assertOk();
        $this->assertSame(0, \App\Models\UserQuestion::query()->count());
        $this->assertSame(2, ChatConfirmation::query()->count(), 'повтор после отказа заводит новую карточку');
    }

    #[Test]
    public function личный_токен_воротами_не_задерживается(): void
    {
        $personal = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Мой ключ', 'is_active' => true]);

        $this->mcpCall($personal, 'client-call', ['operation' => 'questions.create', 'arguments' => ['subject' => 'Объединить заказы', 'body' => 'Прошу отправить заказы 1 и 2 одной посылкой.']])->assertOk();

        $this->assertSame(1, \App\Models\UserQuestion::query()->count());
        $this->assertSame(0, ChatConfirmation::query()->count());
    }

    #[Test]
    public function чужую_карточку_подтвердить_нельзя(): void
    {
        $this->mcpCall($this->token, 'client-call', ['operation' => 'questions.create', 'arguments' => ['subject' => 'Объединить заказы', 'body' => 'Прошу отправить заказы 1 и 2 одной посылкой.']])->assertOk();
        $confirmation = ChatConfirmation::query()->first();
        $other = \App\Models\User::factory()->create();

        $this->actingAs($other)
            ->postJson('/cabinet/assistant/confirmations/'.$confirmation->id, ['approve' => true])
            ->assertNotFound();
    }
}
