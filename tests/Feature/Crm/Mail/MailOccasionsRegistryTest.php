<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Реестр поводов: на что вообще можно подписать партнёра.
 *
 * Экран отвечает на два вопроса — что система умеет присылать и что из этого
 * сейчас не получает никто.
 */
class MailOccasionsRegistryTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    /**
     * Движок правил больше не маршрутизирует уведомления: этим занимается
     * настройка партнёра (эпик note-00). Тесты описывают механизм, который
     * ничего не решает, и уходят вместе с ним в note-08.
     *
     * Пропуск, а не удаление: снос движка — большая необратимая правка,
     * и делать её без присмотра неправильно.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped('Движок правил отключён от маршрутизации — сносится в note-08.');
    }

    #[Test]
    public function реестр_перечисляет_все_поводы_из_конфига(): void
    {
        $response = $this->actingAs($this->manager)
            ->get(route('crm.emails.occasions.index'))
            ->assertOk();

        $occasions = $response->viewData('page')['props']['occasions'];
        $keys = array_column($occasions, 'key');

        $this->assertSame(count(config('mail_occasions')), count($occasions));
        $this->assertContains('orders.status_changed', $keys);
        $this->assertContains('orders.items_updated', $keys);
    }

    #[Test]
    public function повод_показывает_сколько_писем_не_получает_никто(): void
    {
        app(MailStream::class)->capture(new Occasion(
            key: 'orders.status_changed',
            clientUserId: $this->client->id,
            data: [
                'order_id' => 1,
                'order_number' => '1001',
                'status' => 'shipping',
                'status_label' => 'В процессе отгрузки',
            ],
        ));

        $response = $this->actingAs($this->manager)
            ->get(route('crm.emails.occasions.index'))
            ->assertOk();

        $occasions = collect($response->viewData('page')['props']['occasions'])
            ->keyBy('key');

        // Правил нет — письмо ушло мимо фильтров, и повод обязан это показать.
        $this->assertSame(EmailStatus::UNMATCHED, CrmEmail::query()->firstOrFail()->status);
        $this->assertSame(1, $occasions['orders.status_changed']['collected']);
        $this->assertSame(1, $occasions['orders.status_changed']['unmatched']);
        $this->assertSame(0, $occasions['orders.created']['collected']);
    }

    #[Test]
    public function выключенный_домен_виден_на_экране(): void
    {
        config(['mail_stream.domains.system' => false]);

        $response = $this->actingAs($this->manager)
            ->get(route('crm.emails.occasions.index'))
            ->assertOk();

        $occasions = collect($response->viewData('page')['props']['occasions'])->keyBy('key');
        $system = $occasions->first(fn (array $occasion): bool => $occasion['domain'] === 'system');

        $this->assertNotNull($system);
        $this->assertFalse($system['domain_enabled']);
    }

    #[Test]
    public function посторонний_в_реестр_не_попадает(): void
    {
        // Middleware «crm» выкидывает из раздела целиком, не доходя до Gate:
        // человек без доступа в CRM получает редирект, а не 403.
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('crm.emails.occasions.index'))
            ->assertRedirect();
    }
}
