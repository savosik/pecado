<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Notifications\Pulse\PresetApplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Пресеты правил и покрытие адресной книги.
 *
 * Настройка типового контрагента должна занимать одну кнопку — иначе
 * менеджер не станет заводить правила на сотню клиентов и вернётся
 * в мессенджеры, как это уже случилось с подпиской в кабинете.
 */
class PresetsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $partner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        config([
            'notification_pulse.domains.orders.enabled' => true,
            'notification_pulse.domains.documents.enabled' => true,
            'notification_pulse.domains.finance.enabled' => true,
        ]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->partner = User::factory()->create(['personal_manager_id' => $card->id]);
        $this->company = Company::factory()->create(['user_id' => $this->partner->id]);
    }

    private function contact(ClientContactRole $role, string $email): ClientContact
    {
        return ClientContact::factory()->role($role)->create([
            'user_id' => $this->partner->id,
            'company_id' => $this->company->id,
            'email' => $email,
        ]);
    }

    #[Test]
    #[TestDox('Каталог пресетов перечисляет нужные роли')]
    public function catalog_lists_required_roles(): void
    {
        $catalog = app(PresetApplier::class)->catalog();

        $accounting = collect($catalog)->firstWhere('key', 'accounting');

        $this->assertNotNull($accounting);
        $this->assertSame('Бухгалтерия', $accounting['label']);
        $this->assertContains('Бухгалтер', $accounting['roles']);
        $this->assertContains('Директор', $accounting['roles']);
    }

    #[Test]
    #[TestDox('Предпросмотр называет недостающие роли поимённо')]
    public function preview_names_missing_roles(): void
    {
        // Есть только бухгалтер, директора нет
        $this->contact(ClientContactRole::ACCOUNTANT, 'buh@x.ru');

        $preview = app(PresetApplier::class)->preview('accounting', $this->company);

        $this->assertNotEmpty($preview['will_create']);

        $missingRoles = collect($preview['missing'])->pluck('role_label')->unique();
        $this->assertContains('Директор', $missingRoles);
        $this->assertNotContains('Бухгалтер', $missingRoles);
    }

    #[Test]
    #[TestDox('Применение создаёт правила и подставляет контакты по ролям')]
    public function apply_creates_rules(): void
    {
        $this->contact(ClientContactRole::ACCOUNTANT, 'buh@x.ru');
        $this->contact(ClientContactRole::DIRECTOR, 'dir@x.ru');

        $result = app(PresetApplier::class)->apply('accounting', $this->company, $this->manager);

        $this->assertSame(5, $result['created']);
        $this->assertSame([], $result['missing']);

        $rule = NotificationRule::where('event_key', 'finance.payment_due_soon')->sole();
        $this->assertSame($this->company->id, $rule->scope_company_id);
        $this->assertTrue($rule->attach_documents, 'к сроку оплаты прикладывается счёт');
        $this->assertSame(
            ClientContactRole::ACCOUNTANT->value,
            $rule->recipients->firstWhere('kind', NotificationRuleRecipient::KIND_CONTACT_ROLE)->value,
        );
    }

    #[Test]
    #[TestDox('Правило без единого адресата не создаётся')]
    public function rule_without_recipients_is_skipped(): void
    {
        // Контактов нет вовсе — правило молчало бы, а менеджер решил бы, что настроил
        $result = app(PresetApplier::class)->apply('accounting', $this->company, $this->manager);

        $this->assertSame(0, $result['created']);
        $this->assertNotEmpty($result['missing']);
        $this->assertSame(0, NotificationRule::count());
    }

    #[Test]
    #[TestDox('Повторное применение не плодит правила')]
    public function apply_is_idempotent(): void
    {
        $this->contact(ClientContactRole::BUYER, 'buyer@x.ru');

        $first = app(PresetApplier::class)->apply('critical_only', $this->company, $this->manager);
        $second = app(PresetApplier::class)->apply('critical_only', $this->company, $this->manager);

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, NotificationRule::count());
    }

    #[Test]
    #[TestDox('Менеджер применяет пресет через интерфейс')]
    public function manager_applies_preset_via_http(): void
    {
        $this->contact(ClientContactRole::BUYER, 'buyer@x.ru');

        $this->actingAs($this->manager)
            ->post('/crm/notifications/presets/apply', [
                'company_id' => $this->company->id,
                'preset' => 'orders_control',
            ])
            ->assertRedirect();

        $this->assertGreaterThan(0, NotificationRule::count());
    }

    #[Test]
    #[TestDox('Пресет чужому контрагенту не применяется')]
    public function foreign_company_is_refused(): void
    {
        $foreign = Company::factory()->create([
            'user_id' => User::factory()->create([
                'personal_manager_id' => PersonalManager::factory()->create()->id,
            ])->id,
        ]);

        $this->actingAs($this->manager)
            ->post('/crm/notifications/presets/apply', [
                'company_id' => $foreign->id,
                'preset' => 'critical_only',
            ])
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Покрытие показывает, скольким контрагентам есть кому писать')]
    public function coverage_counts_reachable_companies(): void
    {
        $covered = Company::factory()->create(['user_id' => $this->partner->id]);
        ClientContact::factory()->role(ClientContactRole::ACCOUNTANT)->create([
            'user_id' => $this->partner->id,
            'company_id' => $covered->id,
            'email' => 'buh2@x.ru',
        ]);

        // Правило-политика: адресовано роли, действует на всех
        $policy = NotificationRule::factory()->create([
            'name' => 'Просрочка — бухгалтеру',
            'event_key' => 'finance.overdue_started',
            'scope_type' => NotificationRule::SCOPE_GLOBAL,
        ]);
        $policy->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE,
            'value' => ClientContactRole::ACCOUNTANT->value,
        ]);

        $rows = app(PresetApplier::class)->coverage($this->manager);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['covered'], 'только у одного юрлица есть бухгалтер');
        $this->assertSame(2, $rows[0]['total']);
        $this->assertSame(1, $rows[0]['uncovered'], 'дыра должна быть видна цифрой');
    }

    #[Test]
    #[TestDox('Экран покрытия открывается')]
    public function coverage_page_opens(): void
    {
        $this->actingAs($this->manager)->get('/crm/notifications/coverage')->assertOk();
    }
}
