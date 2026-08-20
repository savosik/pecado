<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\CrmClientProfile;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Notifications\ClientContactService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Адресная книга контрагентов (notif-01).
 *
 * Предмет проверки — то, ради чего книга заводилась: адрес принадлежит конкретному
 * партнёру и не может утечь к чужому, роль работает как адресат правила, а импорт
 * из профиля создаёт черновики, а не готовых получателей писем.
 */
class ClientContactsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $card;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->partner = User::factory()->create(['personal_manager_id' => $this->card->id]);
    }

    private function foreignPartner(): User
    {
        return User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    #[Test]
    #[TestDox('Менеджер заводит контакт своему партнёру')]
    public function manager_creates_contact(): void
    {
        $this->actingAs($this->manager)
            ->post('/crm/contacts', [
                'user_id' => $this->partner->id,
                'full_name' => 'Жопкин Анатолий',
                'role' => ClientContactRole::ACCOUNTANT->value,
                'email' => 'BUH@romashka.RU',
            ])
            ->assertRedirect();

        $contact = ClientContact::sole();

        $this->assertSame($this->partner->id, $contact->user_id);
        $this->assertSame(ClientContactRole::ACCOUNTANT, $contact->role);
        // Адрес — ключ доставки: нормализуется, иначе один человек попадёт
        // в правило и в стоп-лист разными строками.
        $this->assertSame('buh@romashka.ru', $contact->email);
        $this->assertNotEmpty($contact->unsubscribe_token);
    }

    #[Test]
    #[TestDox('Контакт чужого партнёра недоступен: 404, а не 403')]
    public function foreign_contact_is_hidden(): void
    {
        $foreign = ClientContact::factory()->create(['user_id' => $this->foreignPartner()->id]);

        $this->actingAs($this->manager)
            ->patch("/crm/contacts/{$foreign->id}", [
                'user_id' => $foreign->user_id,
                'full_name' => 'Взлом',
                'role' => ClientContactRole::OTHER->value,
            ])
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Нельзя завести контакт на чужого партнёра, подставив его id')]
    public function cannot_create_contact_for_foreign_partner(): void
    {
        $this->actingAs($this->manager)
            ->post('/crm/contacts', [
                'user_id' => $this->foreignPartner()->id,
                'full_name' => 'Чужой',
                'role' => ClientContactRole::OTHER->value,
                'email' => 'x@example.com',
            ])
            ->assertNotFound();

        $this->assertSame(0, ClientContact::count());
    }

    #[Test]
    #[TestDox('Юрлицо другого партнёра к контакту не привязывается')]
    public function company_must_belong_to_the_same_partner(): void
    {
        $foreignCompany = Company::factory()->create(['user_id' => $this->foreignPartner()->id]);

        $this->actingAs($this->manager)
            ->post('/crm/contacts', [
                'user_id' => $this->partner->id,
                'company_id' => $foreignCompany->id,
                'full_name' => 'Путаница',
                'role' => ClientContactRole::ACCOUNTANT->value,
                'email' => 'mix@example.com',
            ])
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Повторный адрес у того же партнёра отбивается с понятной ошибкой')]
    public function duplicate_email_is_rejected(): void
    {
        ClientContact::factory()->create([
            'user_id' => $this->partner->id,
            'email' => 'buh@romashka.ru',
        ]);

        $this->actingAs($this->manager)
            ->post('/crm/contacts', [
                'user_id' => $this->partner->id,
                'full_name' => 'Дубль',
                'role' => ClientContactRole::DIRECTOR->value,
                'email' => 'buh@romashka.ru',
            ])
            ->assertStatus(422);

        $this->assertSame(1, ClientContact::count());
    }

    #[Test]
    #[TestDox('Контакт без юрлица годится для любого юрлица партнёра')]
    public function partner_wide_contact_serves_any_company(): void
    {
        $company = Company::factory()->create(['user_id' => $this->partner->id]);

        $contact = ClientContact::factory()->create([
            'user_id' => $this->partner->id,
            'company_id' => null,
        ]);

        $this->assertTrue($contact->belongsToSubject($this->partner->id, $company->id));
        $this->assertTrue($contact->belongsToSubject($this->partner->id, null));
    }

    #[Test]
    #[TestDox('Контакт одного юрлица не годится для другого')]
    public function company_bound_contact_is_not_reused(): void
    {
        $romashka = Company::factory()->create(['user_id' => $this->partner->id]);
        $oduvanchik = Company::factory()->create(['user_id' => $this->partner->id]);

        $contact = ClientContact::factory()->create([
            'user_id' => $this->partner->id,
            'company_id' => $romashka->id,
        ]);

        $this->assertTrue($contact->belongsToSubject($this->partner->id, $romashka->id));
        $this->assertFalse($contact->belongsToSubject($this->partner->id, $oduvanchik->id));
        // И тем более не годится для другого партнёра
        $this->assertFalse($contact->belongsToSubject($this->foreignPartner()->id, $romashka->id));
    }

    #[Test]
    #[TestDox('Выборка по роли отдаёт только годных для доставки')]
    public function deliverable_by_role_skips_inactive_and_unsubscribed(): void
    {
        $company = Company::factory()->create(['user_id' => $this->partner->id]);

        $good = ClientContact::factory()->role(ClientContactRole::ACCOUNTANT)->create([
            'user_id' => $this->partner->id,
            'company_id' => $company->id,
        ]);
        ClientContact::factory()->role(ClientContactRole::ACCOUNTANT)->draft()->create([
            'user_id' => $this->partner->id,
            'company_id' => $company->id,
        ]);
        ClientContact::factory()->role(ClientContactRole::ACCOUNTANT)->unsubscribed()->create([
            'user_id' => $this->partner->id,
            'company_id' => $company->id,
        ]);
        ClientContact::factory()->role(ClientContactRole::ACCOUNTANT)->create([
            'user_id' => $this->partner->id,
            'company_id' => $company->id,
            'email' => null,
        ]);

        $found = app(ClientContactService::class)
            ->deliverableByRole($this->partner->id, $company->id, ClientContactRole::ACCOUNTANT);

        $this->assertCount(1, $found);
        $this->assertSame($good->id, $found->first()->id);
    }

    #[Test]
    #[TestDox('Импорт из профиля создаёт неактивные черновики и не дублирует')]
    public function import_creates_inactive_drafts(): void
    {
        CrmClientProfile::create([
            'user_id' => $this->partner->id,
            'accountant_name' => 'Жопкина Анна',
            'accountant_contact' => '+7 912 345-67-89, buh@romashka.ru',
            'owner_name' => 'Залупкин Виктор',
            'owner_contact' => 'dir@romashka.ru',
            'decision_maker_name' => 'Без почты',
            'decision_maker_contact' => 'только телефон +7 900 000-00-00',
        ]);

        $service = app(ClientContactService::class);

        $first = $service->importFromProfile($this->partner, $this->manager->id);
        $this->assertSame(2, $first['created']);

        $accountant = ClientContact::where('email', 'buh@romashka.ru')->sole();
        $this->assertFalse($accountant->is_active, 'черновик не должен участвовать в рассылке');
        $this->assertSame(ClientContact::SOURCE_PROFILE_IMPORT, $accountant->source);
        $this->assertSame('Жопкина Анна', $accountant->full_name);
        $this->assertSame('+7 912 345-67-89', $accountant->phone);
        $this->assertSame(ClientContactRole::ACCOUNTANT, $accountant->role);

        // Строка без адреса контактом не становится — рассылать некуда
        $this->assertSame(2, ClientContact::count());

        // Повторный запуск идемпотентен
        $second = $service->importFromProfile($this->partner, $this->manager->id);
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, ClientContact::count());
    }

    #[Test]
    #[TestDox('Внутри CRM раздел закрыт без права на контакты')]
    public function permission_is_required(): void
    {
        // Роль с доступом в CRM, но без права адресной книги: проверяем именно
        // право, а не middleware панели — сотрудник без CRM-доступа получает
        // редирект и до проверки права не доходит.
        $role = Role::where('name', 'sales-manager')->where('guard_name', 'web')->first();
        $role->revokePermissionTo('crm-notification-contacts.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->manager)
            ->get('/crm/contacts?user_id='.$this->partner->id)
            ->assertForbidden();
    }
}
