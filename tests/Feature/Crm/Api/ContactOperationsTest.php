<?php

namespace Tests\Feature\Crm\Api;

use App\Enums\ContactRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CrmAgentToken;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Справочник людей глазами ИИ-агента.
 *
 * Главный сценарий: агент разбирает письмо, спрашивает «чей это адрес»,
 * при промахе заводит карточку и привязывает — со следующего письма подшивка
 * идёт сама.
 */
class ContactOperationsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);

        $this->token = CrmAgentToken::issue('Агент', (int) $this->manager->id)->token;
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    #[Test]
    public function agent_learns_whose_address_it_is(): void
    {
        Contact::factory()->forClient($this->client)->create([
            'full_name' => 'Афонина Мария',
            'email' => 'buh@romashka.ru',
        ]);

        $response = $this->getJson('/api/crm/contacts/by-email?email=BUH@romashka.ru', $this->auth());

        $response->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('contact.full_name', 'Афонина Мария')
            ->assertJsonPath('client_user_id', $this->client->id);
    }

    #[Test]
    public function unknown_address_answers_honestly(): void
    {
        $response = $this->getJson('/api/crm/contacts/by-email?email=stranger@example.com', $this->auth());

        $response->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('contact', null);
    }

    #[Test]
    public function agent_creates_a_contact_and_links_it(): void
    {
        $company = Company::factory()->create(['user_id' => $this->client->id]);

        $created = $this->postJson('/api/crm/contacts', [
            'full_name' => 'Петров Иван',
            'email' => 'buyer@romashka.ru',
            'phone' => '+79123456789',
            'client_id' => $this->client->id,
        ], $this->auth());

        $created->assertOk();

        $contactId = $created->json('id');

        $this->postJson("/api/crm/contacts/{$contactId}/links", [
            'entity_type' => 'contractor',
            'entity_id' => $company->id,
            'role' => ContactRole::BUYER->value,
        ], $this->auth())->assertOk();

        $contact = Contact::query()->findOrFail($contactId);

        $this->assertSame($this->client->id, $contact->client_user_id);
        $this->assertSame(1, $contact->links()->count());

        // Ради этого всё и затевалось: со следующего письма адрес узнаётся.
        $this->getJson('/api/crm/contacts/by-email?email=buyer@romashka.ru', $this->auth())
            ->assertOk()
            ->assertJsonPath('found', true);
    }

    #[Test]
    public function foreign_contact_is_not_given_to_the_agent(): void
    {
        $stranger = User::factory()->create();
        $foreign = Contact::factory()->forClient($stranger)->create();

        $this->getJson("/api/crm/contacts/{$foreign->id}", $this->auth())->assertNotFound();
    }

    #[Test]
    public function address_of_a_foreign_contact_stays_hidden(): void
    {
        $stranger = User::factory()->create();
        Contact::factory()->forClient($stranger)->create(['email' => 'secret@rival.ru']);

        $this->getJson('/api/crm/contacts/by-email?email=secret@rival.ru', $this->auth())
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    #[Test]
    public function partner_address_book_comes_in_one_call(): void
    {
        Contact::factory()->count(3)->forClient($this->client)->create();

        $this->getJson("/api/crm/clients/{$this->client->id}/contacts", $this->auth())
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function operations_are_visible_in_the_catalog(): void
    {
        // Реестр единственный: маршрут, OpenAPI и каталог MCP появляются сами.
        $catalog = app(\App\Services\Crm\Api\OperationRegistry::class)->all();
        $ids = array_map(fn ($operation): string => $operation->id, $catalog);

        foreach (['contact.list', 'contact.show', 'contact.by_email', 'contact.create', 'contact.link', 'client.contacts'] as $id) {
            $this->assertContains($id, $ids);
        }
    }
}
