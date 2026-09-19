<?php

namespace Tests\Feature\Assistant;

use App\Events\AssistantAvailabilityChanged;
use App\Services\Assistant\AssistantAvailability;
use App\Services\Assistant\Gateway\GatewayException;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Флаг доступности: кончился баланс — помощник исчезает у всех, пополнили —
 * пробный запрос возвращает его сам.
 */
class AvailabilityTest extends AssistantTestCase
{
    #[Test]
    public function выключенный_рубильник_прячет_помощника_и_его_маршруты(): void
    {
        config()->set('assistant.enabled', false);

        $this->actingAs($this->client)
            ->getJson('/cabinet/assistant/threads')
            ->assertNotFound();

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertInertia(fn ($page) => $page->where('assistant', null));
    }

    #[Test]
    public function клиент_с_юрлицом_видит_помощника_а_гость_и_пользователь_без_юрлица_нет(): void
    {
        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertInertia(fn ($page) => $page->where('assistant.available', true));

        $bare = \App\Models\User::factory()->create();

        $this->actingAs($bare)
            ->get('/cabinet/dashboard')
            ->assertInertia(fn ($page) => $page->where('assistant', null));

        $this->get('/')->assertInertia(fn ($page) => $page->where('assistant', null));
    }

    #[Test]
    public function ошибка_баланса_прячет_помощника_у_всех_и_шлёт_событие(): void
    {
        Event::fake([AssistantAvailabilityChanged::class]);

        $availability = app(AssistantAvailability::class);
        $this->assertTrue($availability->isAvailable());

        $availability->observe(new GatewayException(GatewayException::BILLING, 'Your credit balance is too low', 402));

        $this->assertFalse($availability->isAvailable());
        $this->assertSame('unavailable', $availability->state()['state']);
        Event::assertDispatched(AssistantAvailabilityChanged::class, fn ($e) => $e->available === false);

        $this->actingAs($this->client)
            ->getJson('/cabinet/assistant/threads')
            ->assertNotFound();

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertInertia(fn ($page) => $page->where('assistant', null));
    }

    #[Test]
    public function временная_ошибка_состояние_не_меняет(): void
    {
        $availability = app(AssistantAvailability::class);

        $availability->observe(new GatewayException(GatewayException::RATE_LIMIT, 'slow down', 429));
        $availability->observe(new GatewayException(GatewayException::OVERLOADED, 'overloaded', 529));

        $this->assertTrue($availability->isAvailable());
    }

    #[Test]
    public function проба_возвращает_помощника_после_пополнения(): void
    {
        Event::fake([AssistantAvailabilityChanged::class]);
        $availability = app(AssistantAvailability::class);
        $availability->markUnavailable('billing: пусто');

        $this->gateway->willFail(GatewayException::BILLING, 'still empty', 402);
        $this->artisan('assistant:probe')->assertSuccessful();
        $this->assertFalse($availability->isAvailable());

        $this->gateway->willAnswerText('pong');
        $this->artisan('assistant:probe')->assertSuccessful();
        $this->assertTrue($availability->isAvailable());
        Event::assertDispatched(AssistantAvailabilityChanged::class, fn ($e) => $e->available === true);

        $this->assertSame(8, $this->gateway->lastRequest()['maxTokens']);
    }

    #[Test]
    public function доступному_помощнику_проба_не_стоит_ни_одного_запроса(): void
    {
        $this->artisan('assistant:probe')->assertSuccessful();

        $this->assertSame([], $this->gateway->requests);
    }

    #[Test]
    public function команда_availability_переключает_флаг_руками(): void
    {
        $this->artisan('assistant:availability', ['state' => 'off'])->assertSuccessful();
        $this->assertFalse(app(AssistantAvailability::class)->isAvailable());

        $this->artisan('assistant:availability', ['state' => 'on'])->assertSuccessful();
        $this->assertTrue(app(AssistantAvailability::class)->isAvailable());
    }

    #[Test]
    public function месячный_предел_организации_прячет_помощника(): void
    {
        config()->set('assistant.quotas.org_monthly_usd', 1);

        $thread = app(\App\Services\Assistant\ThreadService::class)->open($this->client);
        \App\Models\ChatMessage::create([
            'thread_id' => $thread->id,
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'text' => 'ok',
            'status' => 'done',
            'cost' => 1.5,
        ]);
        app(\App\Services\Assistant\AssistantQuotas::class)->forgetOrgCache();

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertInertia(fn ($page) => $page->where('assistant', null));

        $this->actingAs($this->client)
            ->getJson('/cabinet/assistant/threads')
            ->assertNotFound();
    }
}
