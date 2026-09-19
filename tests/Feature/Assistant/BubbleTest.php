<?php

namespace Tests\Feature\Assistant;

use App\Services\Assistant\BubbleResolver;
use PHPUnit\Framework\Attributes\Test;

/**
 * Реплики иконки: ответ или действие из данных, никогда «спросить у менеджера».
 */
class BubbleTest extends AssistantTestCase
{
    #[Test]
    public function первый_вход_даёт_знакомство_а_потом_реплику_по_странице(): void
    {
        $intro = $this->actingAs($this->client)
            ->getJson('/cabinet/assistant/bubble?type=catalog&intro=1')
            ->assertOk()->json('bubble');
        $this->assertSame('intro', $intro['key']);

        $catalog = $this->actingAs($this->client)
            ->getJson('/cabinet/assistant/bubble?type=catalog')
            ->assertOk()->json('bubble');
        $this->assertSame('catalog.file', $catalog['key']);
        $this->assertStringContainsString('прайс', $catalog['text']);
        $this->assertNotEmpty($catalog['question']);
    }

    #[Test]
    public function уже_показанная_реплика_не_повторяется(): void
    {
        $bubble = $this->actingAs($this->client)
            ->getJson('/cabinet/assistant/bubble?type=documents&shown=documents.reconciliation')
            ->assertOk()->json('bubble');

        $this->assertNull($bubble);
    }

    #[Test]
    public function неизвестный_товар_даёт_нейтральную_реплику_без_ошибки(): void
    {
        $bubble = $this->actingAs($this->client)
            ->getJson('/cabinet/assistant/bubble?type=product&id=999999&title=Нет')
            ->assertOk()->json('bubble');

        $this->assertSame('product.ask', $bubble['key']);
    }

    #[Test]
    public function ни_одна_реплика_каталога_не_отсылает_к_менеджеру(): void
    {
        $resolver = app(BubbleResolver::class);
        $pages = ['home', 'catalog', 'search', 'cart', 'checkout', 'cabinet', 'orders', 'reserves', 'shipments', 'documents', 'finance', 'returns', 'promotions', 'faq', 'other', 'product', 'order'];

        foreach ($pages as $type) {
            $bubble = $resolver->resolve($this->client, ['type' => $type, 'id' => null, 'title' => null, 'url' => null], false);

            $this->assertNotNull($bubble, "страница {$type} без реплики");
            $this->assertDoesNotMatchRegularExpression('/менеджер|уточн/iu', $bubble['text'], "реплика {$bubble['key']} отсылает к менеджеру");
            $this->assertLessThanOrEqual(90, mb_strlen($bubble['text']));
        }
    }

    #[Test]
    public function выключенные_реплики_не_отдаются(): void
    {
        config()->set('assistant.bubbles.enabled', false);

        $this->actingAs($this->client)
            ->getJson('/cabinet/assistant/bubble?type=catalog')
            ->assertOk()->assertJsonPath('bubble', null);
    }

    #[Test]
    public function страница_помощника_в_кабинете_отдаёт_треды(): void
    {
        $thread = app(\App\Services\Assistant\ThreadService::class)->open($this->client);

        $this->actingAs($this->client)
            ->get('/cabinet/assistant')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/Assistant/Index')
                ->has('threads', 1)
                ->where('threads.0.id', $thread->id));
    }
}
