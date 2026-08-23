<?php

namespace Tests\Feature\Crm;

use App\Enums\ContactRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Раздел «Контакты»: список, карточка, привязки.
 *
 * Проверяется то, ради чего раздел затевался: человек виден одной строкой,
 * сколько бы ролей у него ни было; чужой человек не виден вовсе.
 */
class ContactsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(array $query = []): array
    {
        return $this->actingAs($this->manager)
            ->get(route('crm.contacts.index', $query))
            ->viewData('page')['props'];
    }

    #[Test]
    public function one_person_with_many_roles_is_one_row(): void
    {
        // Если строкой сделать привязку, бухгалтер трёх юрлиц займёт три строки,
        // и менеджер решит, что в базе дубли.
        $contact = Contact::factory()->forClient($this->client)->create(['full_name' => 'Афонина Мария']);

        $first = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Ромашка']);
        $second = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Одуванчик']);

        ContactLink::factory()->to($first, ContactRole::ACCOUNTANT)->create(['contact_id' => $contact->id]);
        ContactLink::factory()->to($second, ContactRole::BUYER)->create(['contact_id' => $contact->id]);

        $rows = $this->props()['contacts']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame('Афонина Мария', $rows[0]['full_name']);
        $this->assertCount(2, $rows[0]['roles']);
        $this->assertCount(2, $rows[0]['links']);
    }

    #[Test]
    public function foreign_contact_is_invisible_and_its_card_answers_404(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('sales-manager');
        $foreignManager = PersonalManager::factory()->create(['user_id' => $stranger->id]);
        $foreignClient = User::factory()->create(['personal_manager_id' => $foreignManager->id]);

        $foreign = Contact::factory()->forClient($foreignClient)->create();

        $ids = array_column($this->props()['contacts']['data'], 'id');
        $this->assertNotContains($foreign->id, $ids);

        // 403 подтвердил бы существование карточки.
        $this->actingAs($this->manager)
            ->get(route('crm.contacts.show', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function phone_is_found_by_digits_however_it_was_typed(): void
    {
        Contact::factory()->forClient($this->client)->create([
            'full_name' => 'Петров Иван',
            'phone' => '+7 (912) 345-67-89',
        ]);
        Contact::factory()->forClient($this->client)->create(['full_name' => 'Сидоров Пётр', 'phone' => '+79990000000']);

        $rows = $this->props(['search' => '345-67-89'])['contacts']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame('Петров Иван', $rows[0]['full_name']);
    }

    #[Test]
    public function inactive_contacts_are_hidden_by_default(): void
    {
        Contact::factory()->forClient($this->client)->create(['full_name' => 'Работает']);
        Contact::factory()->forClient($this->client)->inactive()->create(['full_name' => 'Уволился']);

        $this->assertCount(1, $this->props()['contacts']['data']);
        $this->assertCount(2, $this->props(['activity' => 'all'])['contacts']['data']);
    }

    #[Test]
    public function contact_is_created_and_linked_in_one_step(): void
    {
        // Самый частый путь: человека заводят из карточки контрагента.
        $company = Company::factory()->create(['user_id' => $this->client->id]);

        $response = $this->actingAs($this->manager)->postJson(route('crm.contacts.store'), [
            'full_name' => 'Афонина Мария Петровна',
            'greeting_name' => 'Мария Петровна',
            'position' => 'Главный бухгалтер',
            'email' => 'buh@romashka.ru',
            'phone' => '+7 912 345-67-89',
            'client_id' => $this->client->id,
            'entity_type' => 'contractor',
            'entity_id' => $company->id,
            'role' => ContactRole::ACCOUNTANT->value,
        ]);

        $response->assertCreated()->assertJsonPath('full_name', 'Афонина Мария Петровна');

        $contact = Contact::query()->firstOrFail();

        $this->assertSame($this->client->id, $contact->client_user_id);
        $this->assertSame('79123456789', $contact->phone_digits);
        $this->assertSame(1, $contact->links()->count());
    }

    #[Test]
    public function person_without_any_way_to_reach_them_is_refused(): void
    {
        $response = $this->actingAs($this->manager)->postJson(route('crm.contacts.store'), [
            'full_name' => 'Безымянный',
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('способ связи', $response->json('errors.phone.0'));
    }

    #[Test]
    public function duplicate_email_at_one_partner_is_refused_in_russian(): void
    {
        Contact::factory()->forClient($this->client)->create(['email' => 'buh@romashka.ru']);

        $response = $this->actingAs($this->manager)->postJson(route('crm.contacts.store'), [
            'full_name' => 'Второй бухгалтер',
            'email' => 'BUH@romashka.ru',
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('уже есть контакт с такой почтой', $response->json('errors.email.0'));
    }

    #[Test]
    public function contact_cannot_be_assigned_to_a_foreign_partner(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('sales-manager');
        $foreignManager = PersonalManager::factory()->create(['user_id' => $stranger->id]);
        $foreignClient = User::factory()->create(['personal_manager_id' => $foreignManager->id]);

        $this->actingAs($this->manager)->postJson(route('crm.contacts.store'), [
            'full_name' => 'Чужой человек',
            'phone' => '+79990000000',
            'client_id' => $foreignClient->id,
        ])->assertCreated();

        // Партнёр не проставился: приписать человека чужому партнёру нельзя,
        // даже зная его id.
        $this->assertNull(Contact::query()->firstOrFail()->client_user_id);
    }

    #[Test]
    public function linking_to_a_foreign_entity_answers_404(): void
    {
        $stranger = User::factory()->create();
        $foreignCompany = Company::factory()->create(['user_id' => $stranger->id]);

        $contact = Contact::factory()->forClient($this->client)->create();

        $this->actingAs($this->manager)->postJson(route('crm.contacts.link', $contact), [
            'entity_type' => 'contractor',
            'entity_id' => $foreignCompany->id,
            'role' => ContactRole::ACCOUNTANT->value,
        ])->assertNotFound();

        $this->assertSame(0, ContactLink::query()->count());
    }

    #[Test]
    public function primary_role_holder_is_only_one(): void
    {
        $company = Company::factory()->create(['user_id' => $this->client->id]);

        $first = Contact::factory()->forClient($this->client)->create();
        $second = Contact::factory()->forClient($this->client)->create();

        foreach ([$first, $second] as $contact) {
            $this->actingAs($this->manager)->postJson(route('crm.contacts.link', $contact), [
                'entity_type' => 'contractor',
                'entity_id' => $company->id,
                'role' => ContactRole::ACCOUNTANT->value,
                'is_primary' => true,
            ])->assertOk();
        }

        $this->assertSame(1, ContactLink::query()->where('is_primary', true)->count());
        $this->assertTrue(ContactLink::query()->where('contact_id', $second->id)->first()->is_primary);
    }

    #[Test]
    public function entity_panel_shows_linked_and_partner_wide_contacts(): void
    {
        // На карточке юрлица менеджеру нужны и его контакты, и общие контакты
        // партнёра: бухгалтер бывает один на все юрлица.
        $company = Company::factory()->create(['user_id' => $this->client->id]);

        $linked = Contact::factory()->forClient($this->client)->create(['full_name' => 'Привязанный']);
        ContactLink::factory()->to($company, ContactRole::ACCOUNTANT)->create(['contact_id' => $linked->id]);

        Contact::factory()->forClient($this->client)->create(['full_name' => 'Общий']);

        $response = $this->actingAs($this->manager)->getJson(route('crm.contacts.for-entity', [
            'entity_type' => 'contractor',
            'entity_id' => $company->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.0.contact.full_name', 'Привязанный')
            ->assertJsonPath('partner_contacts.0.full_name', 'Общий');
    }

    #[Test]
    public function author_does_not_lose_a_contact_created_without_a_partner(): void
    {
        // Карточка без партнёра видна тому, кто видит всю базу, — и своему автору.
        // Без второго менеджер, заведший человека из справочника и не указавший
        // партнёра, терял бы его в тот же миг.
        $this->actingAs($this->manager)->postJson(route('crm.contacts.store'), [
            'full_name' => 'Водитель перевозчика',
            'phone' => '+79990000000',
        ])->assertCreated();

        $contact = Contact::query()->firstOrFail();

        $this->assertNull($contact->client_user_id);
        $this->assertContains($contact->id, array_column($this->props()['contacts']['data'], 'id'));
        $this->actingAs($this->manager)->get(route('crm.contacts.show', $contact))->assertOk();
    }

    #[Test]
    public function someone_elses_orphan_contact_stays_hidden(): void
    {
        $colleague = User::factory()->create();
        $colleague->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $colleague->id]);

        $this->actingAs($colleague)->postJson(route('crm.contacts.store'), [
            'full_name' => 'Чужой водитель',
            'phone' => '+79990000001',
        ])->assertCreated();

        $foreign = Contact::query()->firstOrFail();

        $this->assertNotContains($foreign->id, array_column($this->props()['contacts']['data'], 'id'));
        $this->actingAs($this->manager)->get(route('crm.contacts.show', $foreign))->assertNotFound();
    }

    #[Test]
    public function contact_is_linked_from_its_own_card(): void
    {
        // Раньше привязку можно было завести только с карточки партнёра —
        // из справочника человек оставался ничьим.
        $company = Company::factory()->create(['user_id' => $this->client->id]);
        $contact = Contact::factory()->create(['client_user_id' => null, 'created_by_user_id' => $this->manager->id]);

        $this->actingAs($this->manager)->postJson(route('crm.contacts.link', $contact), [
            'entity_type' => 'contractor',
            'entity_id' => $company->id,
            'role' => ContactRole::ACCOUNTANT->value,
        ])->assertOk();

        // Первая привязка проставляет партнёра: карточка перестаёт быть ничьей.
        $this->assertSame($this->client->id, $contact->fresh()->client_user_id);
    }

    #[Test]
    public function entity_search_is_gated_by_the_contacts_permission(): void
    {
        // Поиск живёт под правом контактов, а не задач: менеджер без задач
        // обязан уметь привязать бухгалтера.
        Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Ромашка']);

        $this->actingAs($this->manager)
            ->getJson(route('crm.contacts.entities', ['type' => 'contractor', 'query' => 'Ромаш']))
            ->assertOk()
            ->assertJsonPath('0.label', 'Ромашка');
    }

    #[Test]
    public function without_permission_the_section_is_closed(): void
    {
        $this->manager->roles->first()->revokePermissionTo('crm-contacts.view');
        $this->manager->revokePermissionTo('crm-contacts.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->manager)->get(route('crm.contacts.index'))->assertForbidden();
    }
}
