<?php

namespace Tests\Feature\Crm;

use App\Enums\UserKind;
use App\Models\PersonalManager;
use App\Models\SentEmail;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Письма сайта в ленте карточки партнёра.
 *
 * Менеджер должен видеть в одной хронологии не только то, что написал сам,
 * но и то, что клиенту отправил сайт: иначе на вопрос «а подтверждение заказа
 * ему вообще ушло» ответить можно только из логов почтового сервера.
 */
class ClientTimelineSystemEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create(['user_kind' => UserKind::STAFF]);
        $this->manager->assignRole('sales-manager');

        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create([
            'user_kind' => UserKind::CLIENT,
            'personal_manager_id' => $profile->id,
        ]);
    }

    #[Test]
    public function system_email_appears_in_the_client_timeline(): void
    {
        SentEmail::create([
            'recipient' => 'client@example.com',
            'subject' => 'Заказ ORD-2026-0001 принят — Pecado.ru',
            'client_user_id' => $this->client->id,
            'sent_at' => now(),
        ]);

        $entry = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['system_email']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame('system_email', $entry['type']);
        $this->assertTrue($entry['system']);
        $this->assertNull($entry['author']);
        $this->assertSame('Заказ ORD-2026-0001 принят — Pecado.ru', $entry['title']);
        $this->assertStringContainsString('client@example.com', $entry['excerpt']);
        $this->assertFalse($entry['can']['update']);
        $this->assertFalse($entry['can']['delete']);
    }

    #[Test]
    public function letter_sent_to_the_manager_shows_up_in_the_client_card(): void
    {
        SentEmail::create([
            'recipient' => $this->manager->email,
            'subject' => 'Новый заказ ORD-2026-0002 — Pecado.ru',
            'client_user_id' => $this->client->id,
            'recipient_user_id' => $this->manager->id,
            'sent_at' => now(),
        ]);

        $entry = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['system_email']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        // Получателя показываем именем, а не только адресом: менеджер должен
        // видеть, дошло ли письмо до него или улетело на резервный ящик.
        $this->assertStringContainsString($this->manager->name, $entry['excerpt']);
        $this->assertStringContainsString($this->manager->email, $entry['excerpt']);
    }

    #[Test]
    public function letters_of_another_client_do_not_leak_into_the_card(): void
    {
        $other = User::factory()->create(['user_kind' => UserKind::CLIENT]);

        SentEmail::create([
            'recipient' => 'other@example.com',
            'subject' => 'Чужое письмо',
            'client_user_id' => $other->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['system_email']]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function unattributed_letters_stay_out_of_every_card(): void
    {
        SentEmail::create([
            'recipient' => 'ops@pecado.ru',
            'subject' => 'Проверка здоровья',
            'client_user_id' => null,
            'sent_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['system_email']]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function managers_own_letter_is_not_duplicated_in_the_feed(): void
    {
        // Журнал пишет и письма менеджеров — аудит отправки должен быть полным.
        // Но в ленте такое письмо уже есть записью `email` с автором и текстом,
        // и второй раз, безымянной строкой «письмо от сайта», ему там не место.
        SentEmail::create([
            'recipient' => 'client@example.com',
            'subject' => 'Коммерческое предложение',
            'source' => \App\Mail\CrmManagerMail::class,
            'client_user_id' => $this->client->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['system_email']]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function type_filter_can_hide_system_emails(): void
    {
        SentEmail::create([
            'recipient' => 'client@example.com',
            'subject' => 'Заказ принят',
            'client_user_id' => $this->client->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['comment']]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
