<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Пульт уведомлений в CRM: права, границы видимости, конструктор.
 *
 * Ключевое разграничение: менеджер ведёт правила своих партнёров, а правила
 * «для всех партнёров» — только руководитель. Массовая рассылка по базе
 * не должна быть в руках одного менеджера.
 */
class NotificationRulesCrmTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $head;

    private User $partner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        config(['notification_pulse.domains.orders.enabled' => true]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->partner = User::factory()->create(['personal_manager_id' => $card->id]);
        $this->company = Company::factory()->create(['user_id' => $this->partner->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Недобор — закупщикам',
            'event_key' => 'orders.shortfall',
            'scope_type' => NotificationRule::SCOPE_COMPANY,
            'scope_company_id' => $this->company->id,
            'priority' => 100,
            'recipients' => [
                ['kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE, 'value' => ClientContactRole::BUYER->value],
            ],
        ], $overrides);
    }

    #[Test]
    #[TestDox('Менеджер открывает пульт и создаёт правило своему контрагенту')]
    public function manager_creates_rule(): void
    {
        $this->actingAs($this->manager)->get('/crm/notifications/rules')->assertOk();

        $this->actingAs($this->manager)
            ->post('/crm/notifications/rules', $this->payload())
            ->assertRedirect();

        $rule = NotificationRule::sole();
        $this->assertSame($this->company->id, $rule->scope_company_id);
        $this->assertCount(1, $rule->recipients);
    }

    #[Test]
    #[TestDox('Менеджер не может завести правило для всех партнёров')]
    public function manager_cannot_create_global_rule(): void
    {
        $this->actingAs($this->manager)
            ->post('/crm/notifications/rules', $this->payload([
                'scope_type' => NotificationRule::SCOPE_GLOBAL,
                'scope_company_id' => null,
            ]))
            ->assertForbidden();

        $this->assertSame(0, NotificationRule::count());
    }

    #[Test]
    #[TestDox('Руководитель заводит правило-политику на всю базу')]
    public function head_creates_policy_rule(): void
    {
        $this->actingAs($this->head)
            ->post('/crm/notifications/rules', $this->payload([
                'scope_type' => NotificationRule::SCOPE_GLOBAL,
                'scope_company_id' => null,
            ]))
            ->assertRedirect();

        $rule = NotificationRule::sole();
        $this->assertTrue($rule->isPolicy());
    }

    #[Test]
    #[TestDox('Правило чужого контрагента недоступно: 404')]
    public function foreign_rule_is_hidden(): void
    {
        $foreignCompany = Company::factory()->create([
            'user_id' => User::factory()->create([
                'personal_manager_id' => PersonalManager::factory()->create()->id,
            ])->id,
        ]);

        $rule = NotificationRule::factory()->forCompany($foreignCompany->id)->create();

        $this->actingAs($this->manager)
            ->post("/crm/notifications/rules/{$rule->id}/toggle")
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Конкретный контакт запрещён в правиле для всех партнёров')]
    public function contact_is_refused_in_global_rule(): void
    {
        $contact = ClientContact::factory()->create([
            'user_id' => $this->partner->id,
            'company_id' => $this->company->id,
        ]);

        // Конкретный человек бессмыслен в правиле «для всех»: событие другого
        // клиента к нему отношения не имеет, и письмо ушло бы не по адресу.
        $this->actingAs($this->head)
            ->post('/crm/notifications/rules', $this->payload([
                'scope_type' => NotificationRule::SCOPE_GLOBAL,
                'scope_company_id' => null,
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_CONTACT, 'contact_id' => $contact->id],
                ],
            ]))
            ->assertStatus(422);
    }

    #[Test]
    #[TestDox('Условие с неизвестным полем отбивается с пояснением')]
    public function invalid_condition_is_refused(): void
    {
        $this->actingAs($this->manager)
            ->post('/crm/notifications/rules', $this->payload([
                'conditions' => ['field' => 'wat', 'op' => '=', 'value' => 'x'],
            ]))
            ->assertStatus(422);
    }

    #[Test]
    #[TestDox('Список адресов из настроек принимается только из белого списка')]
    public function config_list_is_whitelisted(): void
    {
        $this->actingAs($this->head)
            ->post('/crm/notifications/rules', $this->payload([
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_CONFIG_LIST, 'value' => 'app.key'],
                ],
            ]))
            ->assertStatus(422);
    }

    #[Test]
    #[TestDox('Системное правило нельзя удалить')]
    public function system_rule_cannot_be_deleted(): void
    {
        $rule = NotificationRule::factory()->create([
            'is_system' => true,
            'system_key' => 'sys.test',
        ]);

        $this->actingAs($this->head)
            ->delete("/crm/notifications/rules/{$rule->id}")
            ->assertStatus(422);

        $this->assertNotNull($rule->fresh());
    }

    #[Test]
    #[TestDox('Проверочное письмо уходит только на адрес самого сотрудника')]
    public function test_send_goes_to_actor_only(): void
    {
        Notification::fake();

        $rule = NotificationRule::factory()->forCompany($this->company->id)->create();

        $this->actingAs($this->manager)
            ->post("/crm/notifications/rules/{$rule->id}/test-send")
            ->assertRedirect();

        Notification::assertSentOnDemand(
            \App\Notifications\Pulse\PulseNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $this->manager->email,
        );
    }

    #[Test]
    #[TestDox('Предпросмотр не отправляет ни одного письма')]
    public function preview_sends_nothing(): void
    {
        Notification::fake();

        $rule = NotificationRule::factory()->forCompany($this->company->id)->create([
            'event_key' => 'orders.shortfall',
        ]);
        $rule->recipients()->create(['kind' => NotificationRuleRecipient::KIND_CLIENT_USER]);

        $this->actingAs($this->manager)
            ->post('/crm/notifications/rules/preview', [
                'event_key' => 'orders.shortfall',
                'company_id' => $this->company->id,
            ])
            ->assertOk();

        Notification::assertNothingSent();
    }

    #[Test]
    #[TestDox('Пересказ правила читается по-русски')]
    public function rule_is_humanized(): void
    {
        $this->actingAs($this->manager)->post('/crm/notifications/rules', $this->payload([
            'conditions' => ['field' => 'shortfall_items_count', 'op' => '>', 'value' => 2],
        ]));

        $humanized = app(\App\Services\Notifications\Pulse\NotificationRuleService::class)
            ->humanize(NotificationRule::sole());

        $this->assertStringContainsString('Недобор по заказу', $humanized);
        $this->assertStringContainsString('Позиций с недобором больше', $humanized);
        $this->assertStringContainsString('все контакты роли', $humanized);
    }

    #[Test]
    #[TestDox('Без права раздел закрыт')]
    public function permission_is_required(): void
    {
        $role = \Spatie\Permission\Models\Role::where('name', 'sales-manager')->first();
        $role->revokePermissionTo('crm-notifications.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->manager)->get('/crm/notifications/rules')->assertForbidden();
    }
}
