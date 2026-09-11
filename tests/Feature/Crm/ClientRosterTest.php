<?php

namespace Tests\Feature\Crm;

use App\Enums\UserKind;
use App\Models\CrmClientStatusChange;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePartnerUpdated;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Состав отдела: кто считается клиентом и какие карточки менеджеров рабочие.
 *
 * 1С присылает партнёрами всех подряд — закупщиков, собственных сотрудников,
 * технические учётки — и заводит карточку менеджера каждому, кого хоть раз
 * указали в документе. Чистить это должен тот, кто отвечает за отдел, не открывая
 * админку и не дожидаясь разработчика.
 */
class ClientRosterTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    private PersonalManager $managerProfile;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // РОП с включённой галочкой «Нераспределённые»: без неё лид для него
        // невидим, а закреплять из карточки — нечего.
        $this->head = User::factory()->staff()->create(['crm_show_unassigned' => true]);
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $this->managerProfile->id]);
    }

    // --- Тип аккаунта -------------------------------------------------------

    #[Test]
    public function head_removes_account_from_client_base(): void
    {
        $this->actingAs($this->head)
            ->put(route('crm.clients.kind.update', $this->client->id), [
                'user_kind' => 'staff',
                'reason' => 'Закупщик, сам не покупает',
            ])
            ->assertRedirect(route('crm.clients.index'));

        $this->assertSame(UserKind::STAFF, $this->client->fresh()->user_kind);

        // Из клиентской выборки CRM аккаунт выпадает сразу.
        $this->assertFalse(
            User::query()->visibleInCrm($this->head)->whereKey($this->client->id)->exists(),
        );
    }

    #[Test]
    public function kind_change_is_written_to_the_status_journal(): void
    {
        $this->actingAs($this->head)->put(route('crm.clients.kind.update', $this->client->id), [
            'user_kind' => 'service',
            'reason' => 'Тестовая учётка',
        ]);

        $entry = CrmClientStatusChange::query()
            ->where('field', CrmClientStatusChange::FIELD_KIND)
            ->firstOrFail();

        $this->assertSame($this->client->id, $entry->client_user_id);
        $this->assertSame('client', $entry->from_value);
        $this->assertSame('service', $entry->to_value);
        $this->assertSame($this->head->id, $entry->user_id);
        $this->assertSame('Тестовая учётка', $entry->reason);
    }

    #[Test]
    public function account_can_be_returned_to_the_client_base(): void
    {
        $this->client->update(['user_kind' => UserKind::STAFF->value]);

        $this->actingAs($this->head)
            ->put(route('crm.clients.kind.update', $this->client->id), ['user_kind' => 'client'])
            ->assertRedirect();

        $this->assertSame(UserKind::CLIENT, $this->client->fresh()->user_kind);
        $this->assertTrue(
            User::query()->visibleInCrm($this->head)->whereKey($this->client->id)->exists(),
        );
    }

    #[Test]
    public function manager_cannot_change_kind_of_own_client(): void
    {
        $this->actingAs($this->manager)
            ->put(route('crm.clients.kind.update', $this->client->id), ['user_kind' => 'staff'])
            ->assertForbidden();

        $this->assertSame(UserKind::CLIENT, $this->client->fresh()->user_kind);
    }

    #[Test]
    public function head_can_change_kind_of_account_without_manager(): void
    {
        // Лид — партнёр без менеджера — с v16.10.0 часть базы отдела, и
        // «это не партнёр» к нему применимо так же, как к закреплённому.
        $lead = User::factory()->create(['personal_manager_id' => null]);

        $this->actingAs($this->head)
            ->put(route('crm.clients.kind.update', $lead->id), ['user_kind' => 'staff'])
            ->assertRedirect(route('crm.clients.index'));

        $this->assertSame(UserKind::STAFF, $lead->fresh()->user_kind);
    }

    // --- Мягкое удаление ----------------------------------------------------

    #[Test]
    public function head_soft_deletes_account(): void
    {
        $this->client->createToken('api');

        $this->actingAs($this->head)
            ->put(route('crm.clients.kind.update', $this->client->id), [
                'user_kind' => 'deleted',
                'reason' => 'Спам или бот-регистрация: десять регистраций за ночь',
            ])
            ->assertRedirect(route('crm.clients.index'));

        $client = $this->client->fresh();
        $this->assertSame(UserKind::DELETED, $client->user_kind);
        $this->assertNotNull($client->deleted_at);
        // Персональные API-токены отозваны вместе с аккаунтом.
        $this->assertSame(0, $client->tokens()->count());
        $this->assertFalse(User::query()->visibleInCrm($this->head)->whereKey($client->id)->exists());
        $this->assertFalse(User::query()->notDeleted()->whereKey($client->id)->exists());
        // Строка на месте: заказы, документы и журнал ссылаются на неё как раньше.
        $this->assertDatabaseHas('users', ['id' => $client->id]);
        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $client->id,
            'field' => CrmClientStatusChange::FIELD_KIND,
            'to_value' => 'deleted',
            'reason' => 'Спам или бот-регистрация: десять регистраций за ночь',
        ]);
    }

    #[Test]
    public function deleted_account_cannot_log_in(): void
    {
        $this->client->update(['password' => 'secret-password', 'user_kind' => UserKind::DELETED->value]);

        // Та же ошибка, что при неверном пароле: подтверждать, что учётка была, незачем.
        $this->post('/login', ['email' => $this->client->email, 'password' => 'secret-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function deleted_account_is_logged_out_on_next_request(): void
    {
        $this->client->update(['user_kind' => UserKind::DELETED->value]);

        $this->actingAs($this->client)
            ->get('/')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function erp_update_does_not_resurrect_deleted_account(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440099';
        $this->client->update(['erp_id' => $uuid, 'user_kind' => UserKind::DELETED->value]);

        // 1С про пометку не знает и шлёт partner.updated как обычно: реквизиты
        // обновляются, но аккаунт остаётся удалённым — владелец поля сайт.
        (new HandlePartnerUpdated)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-deleted',
            'uuid' => $uuid,
            'login' => $this->client->email,
            'name' => 'Снова партнёр',
        ]);

        $client = $this->client->fresh();
        $this->assertSame(UserKind::DELETED, $client->user_kind);
        $this->assertNotNull($client->deleted_at);
        $this->assertSame('Снова партнёр', $client->erp_name);
    }

    #[Test]
    public function deleted_account_can_be_restored(): void
    {
        $this->client->update(['user_kind' => UserKind::DELETED->value]);
        $this->assertNotNull($this->client->fresh()->deleted_at);

        $this->actingAs($this->head)
            ->put(route('crm.clients.kind.update', $this->client->id), ['user_kind' => 'client'])
            ->assertRedirect();

        $client = $this->client->fresh();
        $this->assertSame(UserKind::CLIENT, $client->user_kind);
        $this->assertNull($client->deleted_at);
        $this->assertTrue(User::query()->visibleInCrm($this->head)->whereKey($client->id)->exists());
    }

    // --- Закрепление за менеджером ------------------------------------------

    #[Test]
    public function head_assigns_manager_to_lead(): void
    {
        $lead = User::factory()->create(['personal_manager_id' => null]);

        $this->actingAs($this->head)
            ->put(route('crm.clients.manager.update', $lead->id), [
                'personal_manager_id' => $this->managerProfile->id,
                'reason' => 'Регион менеджера',
            ])
            ->assertRedirect();

        $this->assertSame($this->managerProfile->id, $lead->fresh()->personal_manager_id);

        $entry = CrmClientStatusChange::query()
            ->where('field', CrmClientStatusChange::FIELD_MANAGER)
            ->firstOrFail();

        $this->assertSame($lead->id, $entry->client_user_id);
        $this->assertNull($entry->from_value);
        $this->assertSame((string) $this->managerProfile->id, $entry->to_value);
        $this->assertSame($this->head->id, $entry->user_id);
        $this->assertSame('Регион менеджера', $entry->reason);
    }

    #[Test]
    public function lead_is_out_of_reach_while_checkbox_is_off(): void
    {
        $this->head->forceFill(['crm_show_unassigned' => false])->save();
        $lead = User::factory()->create(['personal_manager_id' => null]);

        $this->actingAs($this->head)
            ->put(route('crm.clients.manager.update', $lead->id), [
                'personal_manager_id' => $this->managerProfile->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function head_unassigns_manager_and_partner_stays_in_crm(): void
    {
        $this->actingAs($this->head)
            ->put(route('crm.clients.manager.update', $this->client->id), [
                'personal_manager_id' => null,
            ])
            ->assertRedirect();

        $this->assertNull($this->client->fresh()->personal_manager_id);
        $this->assertTrue(
            User::query()->visibleInCrm($this->head)->whereKey($this->client->id)->exists(),
            'Партнёр без менеджера остаётся в базе отдела, а не уходит в админку.',
        );
        $this->assertFalse(
            User::query()->visibleInCrm($this->manager)->whereKey($this->client->id)->exists(),
            'У бывшего менеджера партнёр пропадает.',
        );

        $entry = CrmClientStatusChange::query()
            ->where('field', CrmClientStatusChange::FIELD_MANAGER)
            ->firstOrFail();

        $this->assertSame((string) $this->managerProfile->id, $entry->from_value);
        $this->assertSame(CrmClientStatusChange::MANAGER_NONE, $entry->to_value);
    }

    #[Test]
    public function head_without_unassigned_checkbox_returns_to_list_after_unassigning(): void
    {
        // Ровно случай с прода: РОП без галочки «Нераспределённые» снял менеджера
        // из карточки, back() вёл на неё же — а лид для него невидим, отсюда 404.
        $this->head->forceFill(['crm_show_unassigned' => false])->save();

        $this->actingAs($this->head)
            ->from(route('crm.clients.show', $this->client->id))
            ->put(route('crm.clients.manager.update', $this->client->id), [
                'personal_manager_id' => null,
            ])
            ->assertRedirect(route('crm.clients.index'))
            ->assertSessionHas('success');

        // Смена сохранена, галочка не включена за РОПа.
        $this->assertNull($this->client->fresh()->personal_manager_id);
        $this->assertFalse($this->head->fresh()->crm_show_unassigned);
    }

    #[Test]
    public function head_with_unassigned_checkbox_stays_on_the_card_after_unassigning(): void
    {
        $card = route('crm.clients.show', $this->client->id);

        $this->actingAs($this->head)
            ->from($card)
            ->put(route('crm.clients.manager.update', $this->client->id), [
                'personal_manager_id' => null,
            ])
            ->assertRedirect($card);

        $this->actingAs($this->head)->get($card)->assertOk();
    }

    #[Test]
    public function reassignment_is_visible_in_status_history(): void
    {
        $other = PersonalManager::factory()->create(['name' => 'Курочкина']);

        $this->actingAs($this->head)
            ->put(route('crm.clients.manager.update', $this->client->id), [
                'personal_manager_id' => $other->id,
            ]);

        $history = app(\App\Services\Crm\ClientLifecycleService::class)->history($this->client->fresh());

        $this->assertSame('Персональный менеджер', $history[0]['field_label']);
        $this->assertSame($this->managerProfile->name, $history[0]['from']);
        $this->assertSame('Курочкина', $history[0]['to']);
    }

    #[Test]
    public function same_manager_does_not_write_journal_entry(): void
    {
        $this->actingAs($this->head)
            ->put(route('crm.clients.manager.update', $this->client->id), [
                'personal_manager_id' => $this->managerProfile->id,
            ])
            ->assertRedirect();

        $this->assertSame(0, CrmClientStatusChange::query()->where('field', CrmClientStatusChange::FIELD_MANAGER)->count());
    }

    #[Test]
    public function hidden_manager_card_cannot_receive_partners(): void
    {
        $hidden = PersonalManager::factory()->create(['is_active' => false]);

        $this->actingAs($this->head)
            ->put(route('crm.clients.manager.update', $this->client->id), [
                'personal_manager_id' => $hidden->id,
            ])
            ->assertNotFound();

        $this->assertSame($this->managerProfile->id, $this->client->fresh()->personal_manager_id);
    }

    #[Test]
    public function manager_cannot_reassign_own_client(): void
    {
        $other = PersonalManager::factory()->create();

        $this->actingAs($this->manager)
            ->put(route('crm.clients.manager.update', $this->client->id), [
                'personal_manager_id' => $other->id,
            ])
            ->assertForbidden();

        $this->assertSame($this->managerProfile->id, $this->client->fresh()->personal_manager_id);
    }

    #[Test]
    public function partner_card_offers_manager_list_only_to_head(): void
    {
        PersonalManager::factory()->create(['is_active' => false, 'name' => 'Скрытая']);

        $this->actingAs($this->head)
            ->get(route('crm.clients.show', $this->client->id))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->has('managers', 1)
                ->where('managers.0.id', $this->managerProfile->id));

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client->id))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('managers', 0));
    }

    // --- Карточки менеджеров ------------------------------------------------

    #[Test]
    public function head_hides_and_restores_a_manager_card(): void
    {
        $this->actingAs($this->head)
            ->put(route('crm.team.active', $this->managerProfile->id), ['is_active' => false])
            ->assertRedirect();

        $this->assertFalse($this->managerProfile->fresh()->is_active);

        $this->actingAs($this->head)
            ->put(route('crm.team.active', $this->managerProfile->id), ['is_active' => true])
            ->assertRedirect();

        $this->assertTrue($this->managerProfile->fresh()->is_active);
    }

    #[Test]
    public function hidden_card_disappears_from_crm_selectors(): void
    {
        $junk = PersonalManager::factory()->create(['name' => 'Дубль из 1С', 'is_active' => false]);

        // Фильтр списка клиентов.
        $this->actingAs($this->head)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'managers',
                fn ($managers) => collect($managers)->doesntContain('id', $junk->id),
            ));

        // Сетка планов и переключатель скоупа на вкладке «Выполнение».
        $this->actingAs($this->head)
            ->get(route('crm.plans.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('managers', fn ($rows) => collect($rows)->doesntContain('id', $junk->id))
                ->where('managerOptions', fn ($rows) => collect($rows)->doesntContain('id', $junk->id)));

        $options = $this->actingAs($this->head)
            ->getJson(route('crm.plans.progress'))
            ->assertOk()
            ->json('scopeOptions');

        $this->assertNotContains($junk->id, array_column($options, 'id'));
    }

    #[Test]
    public function hidden_card_still_shows_on_the_team_page(): void
    {
        $this->managerProfile->update(['is_active' => false]);

        $this->actingAs($this->head)
            ->get(route('crm.team.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canEdit', true)
                ->where('managers', fn ($rows) => collect($rows)
                    ->firstWhere('id', $this->managerProfile->id)['is_active'] === false));
    }

    #[Test]
    public function manager_cannot_hide_a_card(): void
    {
        $this->actingAs($this->manager)
            ->put(route('crm.team.active', $this->managerProfile->id), ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($this->managerProfile->fresh()->is_active);
    }
}
