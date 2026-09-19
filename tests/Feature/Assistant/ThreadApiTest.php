<?php

namespace Tests\Feature\Assistant;

use App\Jobs\Assistant\RunAssistantTurn;
use App\Models\ChatAttachment;
use App\Models\ChatEvent;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\Assistant\ThreadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * JSON-эндпоинты виджета: открыть, написать, опросить, закрыть, вложения, воронка.
 */
class ThreadApiTest extends AssistantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RunAssistantTurn::class]);
        Storage::fake('local');
    }

    #[Test]
    public function открытие_продолжает_открытый_тред_а_fresh_заводит_новый(): void
    {
        $first = $this->actingAs($this->client)->postJson('/cabinet/assistant/threads', ['page' => ['type' => 'product', 'id' => '5']])
            ->assertOk()->json('thread.id');

        $again = $this->actingAs($this->client)->postJson('/cabinet/assistant/threads')->assertOk()->json('thread.id');
        $this->assertSame($first, $again);

        $fresh = $this->actingAs($this->client)->postJson('/cabinet/assistant/threads', ['fresh' => true])->assertOk()->json('thread.id');
        $this->assertNotSame($first, $fresh);
        $this->assertSame('product', ChatThread::find($first)->page['type']);
    }

    #[Test]
    public function сообщение_создаёт_ход_клиента_заглушку_ответа_и_ставит_job(): void
    {
        $thread = app(ThreadService::class)->open($this->client);

        $response = $this->actingAs($this->client)
            ->postJson("/cabinet/assistant/threads/{$thread->id}/messages", ['text' => 'Покажи мой долг'])
            ->assertOk();

        $response->assertJsonPath('busy', true)->assertJsonCount(2, 'messages');
        $this->assertSame('user', $response->json('messages.0.role'));
        $this->assertSame('Покажи мой долг', $response->json('messages.0.text'));
        $this->assertSame('pending', $response->json('messages.1.status'));
        $this->assertSame('Покажи мой долг', $thread->refresh()->title);

        Bus::assertDispatched(RunAssistantTurn::class, fn ($job) => $job->threadId === $thread->id);
        $this->assertSame(1, ChatEvent::query()->where('event', ChatEvent::FIRST_MESSAGE)->count());

        // Пока модель отвечает, второе сообщение не принимается.
        $this->actingAs($this->client)
            ->postJson("/cabinet/assistant/threads/{$thread->id}/messages", ['text' => 'Ещё'])
            ->assertStatus(409)
            ->assertJsonPath('refused', 'busy');
    }

    #[Test]
    public function опрос_отдаёт_только_новые_и_незавершённые_ходы(): void
    {
        $thread = app(ThreadService::class)->open($this->client);
        $this->actingAs($this->client)->postJson("/cabinet/assistant/threads/{$thread->id}/messages", ['text' => 'Раз'])->assertOk();
        $userMessageId = $thread->messages()->where('role', 'user')->value('id');

        $state = $this->actingAs($this->client)
            ->getJson("/cabinet/assistant/threads/{$thread->id}?after={$userMessageId}")
            ->assertOk()->json();

        $this->assertCount(1, $state['messages']);
        $this->assertSame('pending', $state['messages'][0]['status']);
        $this->assertTrue($state['busy']);
    }

    #[Test]
    public function чужой_тред_недоступен(): void
    {
        $thread = app(ThreadService::class)->open($this->client);
        $other = \App\Models\User::factory()->create();

        $this->actingAs($other)->getJson("/cabinet/assistant/threads/{$thread->id}")->assertNotFound();
        $this->actingAs($other)->postJson("/cabinet/assistant/threads/{$thread->id}/messages", ['text' => 'x'])->assertNotFound();
    }

    #[Test]
    public function квота_юрлица_отказывает_по_русски_и_не_прячет_иконку(): void
    {
        config()->set('assistant.quotas.company_daily_turns', 1);
        $thread = app(ThreadService::class)->open($this->client);
        ChatMessage::create(['thread_id' => $thread->id, 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'ок']], 'text' => 'ок', 'status' => 'done']);

        $this->actingAs($this->client)
            ->postJson("/cabinet/assistant/threads/{$thread->id}/messages", ['text' => 'Ещё'])
            ->assertStatus(409)
            ->assertJsonPath('refused', 'daily_turns')
            ->assertJsonPath('message', 'На сегодня лимит сообщений исчерпан, завтра помощник снова на связи. Срочное — задайте вопрос менеджеру.');

        $this->actingAs($this->client)->get('/cabinet/dashboard')
            ->assertInertia(fn ($page) => $page->where('assistant.available', true));
    }

    #[Test]
    public function вложение_разрешённого_формата_принимается_а_чужого_отклоняется_по_русски(): void
    {
        $thread = app(ThreadService::class)->open($this->client);

        $ok = $this->actingAs($this->client)
            ->post("/cabinet/assistant/threads/{$thread->id}/attachments", ['file' => UploadedFile::fake()->image('price.png', 200, 100)])
            ->assertCreated();
        $this->assertSame('image', $ok->json('attachment.kind'));
        $this->assertSame('price.png', $ok->json('attachment.name'));

        $exe = UploadedFile::fake()->createWithContent('virus.exe', "MZ\x90\x00\x03");
        $this->actingAs($this->client)
            ->postJson("/cabinet/assistant/threads/{$thread->id}/attachments", ['file' => $exe])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Формат .exe не поддерживается. Подойдут фото, PDF, Excel, CSV и Word.');

        // xlsx под именем .png — по содержимому это не картинка.
        $fake = UploadedFile::fake()->createWithContent('price.png', "PK\x03\x04".str_repeat('x', 100));
        $this->actingAs($this->client)
            ->postJson("/cabinet/assistant/threads/{$thread->id}/attachments", ['file' => $fake])
            ->assertStatus(422);

        config()->set('assistant.attachments.max_size_kb', 1);
        $big = UploadedFile::fake()->image('big.png', 2000, 2000)->size(2048);
        $this->actingAs($this->client)
            ->postJson("/cabinet/assistant/threads/{$thread->id}/attachments", ['file' => $big])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function вложение_уходит_заглушкой_в_ход_и_блоком_file_id_в_запрос(): void
    {
        $thread = app(ThreadService::class)->open($this->client);
        $id = $this->actingAs($this->client)
            ->post("/cabinet/assistant/threads/{$thread->id}/attachments", ['file' => UploadedFile::fake()->image('price.png')])
            ->json('attachment.id');

        $this->actingAs($this->client)
            ->postJson("/cabinet/assistant/threads/{$thread->id}/messages", ['text' => 'Найди дешевле', 'attachments' => [$id]])
            ->assertOk();

        $user = $thread->messages()->where('role', 'user')->first();
        $this->assertSame(['type' => 'x-attachment', 'id' => $id], $user->content[0]);
        $this->assertSame($user->id, ChatAttachment::find($id)->message_id);

        $this->gateway->willAnswerText('Смотрю.');
        app(\App\Services\Assistant\TurnRunner::class)->run($thread->messages()->where('role', 'assistant')->value('id'));

        $this->assertCount(1, $this->gateway->uploads);
        $sent = $this->gateway->lastRequest()['messages'][0]['content'][0];
        $this->assertSame('image', $sent['type']);
        $this->assertSame('file_fake_1', $sent['source']['file_id']);
        $this->assertSame('file_fake_1', ChatAttachment::find($id)->anthropic_file_id);
    }

    #[Test]
    public function событие_воронки_пишется_без_текста(): void
    {
        $this->actingAs($this->client)
            ->postJson('/cabinet/assistant/events', ['event' => 'bubble_clicked', 'page' => 'product', 'prompt_key' => 'product.in_stock'])
            ->assertOk();

        $this->actingAs($this->client)
            ->postJson('/cabinet/assistant/events', ['event' => 'first_message'])
            ->assertStatus(422);

        $event = ChatEvent::query()->first();
        $this->assertSame('bubble_clicked', $event->event);
        $this->assertSame('product.in_stock', $event->prompt_key);
    }

    #[Test]
    public function закрытие_треда_отзывает_токен_и_запрещает_писать(): void
    {
        $thread = app(ThreadService::class)->open($this->client);
        $token = app(\App\Services\Assistant\AssistantTokenIssuer::class)->forThread($thread);

        $this->actingAs($this->client)->postJson("/cabinet/assistant/threads/{$thread->id}/close")->assertOk()
            ->assertJsonPath('thread.status', 'closed');

        $this->assertFalse($token->refresh()->is_active);
        $this->actingAs($this->client)
            ->postJson("/cabinet/assistant/threads/{$thread->id}/messages", ['text' => 'x'])
            ->assertStatus(409)->assertJsonPath('refused', 'closed');
    }
}
