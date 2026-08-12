<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\CrmComment;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Раздел «Контрагенты» — юрлица партнёров.
 *
 * Проверяем ровно то, что отличает раздел от списка партнёров: границу видимости
 * (юрлицо чужого партнёра и юрлицо без партнёра) и то, что задачи с комментариями
 * ложатся на контрагента и попадают в ленту его партнёра.
 */
class ContractorsTest extends TestCase
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

    private function contractorFor(?User $partner): Company
    {
        return Company::factory()->create([
            'user_id' => $partner?->id,
            'name' => 'Юрлицо '.($partner?->id ?? 'без партнёра'),
        ]);
    }

    private function foreignPartner(): User
    {
        return User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    private function departmentHead(): User
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        return $head;
    }

    #[Test]
    #[TestDox('Список показывает юрлица своих партнёров и скрывает чужие')]
    public function list_is_limited_to_own_partners(): void
    {
        $own = $this->contractorFor($this->partner);
        $foreign = $this->contractorFor($this->foreignPartner());

        $this->actingAs($this->manager)
            ->get(route('crm.contractors.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($own, $foreign) {
                $ids = collect($page->toArray()['props']['contractors']['data'])->pluck('id');

                $this->assertTrue($ids->contains($own->id), 'Своё юрлицо обязано быть в списке.');
                $this->assertFalse($ids->contains($foreign->id), 'Чужое юрлицо в список попасть не должно.');
            });
    }

    #[Test]
    #[TestDox('Контрагент без партнёра виден только тому, кто видит отдел целиком')]
    public function orphan_contractor_is_visible_to_head_only(): void
    {
        $orphan = $this->contractorFor(null);

        $this->actingAs($this->manager)
            ->get(route('crm.contractors.show', $orphan))
            ->assertNotFound();

        $this->actingAs($this->departmentHead())
            ->get(route('crm.contractors.show', $orphan))
            ->assertOk();
    }

    #[Test]
    #[TestDox('Карточка чужого контрагента отвечает 404, а не 403')]
    public function foreign_contractor_card_is_not_found(): void
    {
        $foreign = $this->contractorFor($this->foreignPartner());

        $this->actingAs($this->manager)
            ->get(route('crm.contractors.show', $foreign))
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Карточка отдаёт реквизиты, партнёра и баланс из 1С')]
    public function card_returns_requisites_partner_and_balance(): void
    {
        $contractor = $this->contractorFor($this->partner);

        ContractorBalance::create([
            'user_id' => $this->partner->id,
            'company_id' => $contractor->id,
            'tax_id' => (string) $contractor->tax_id,
            'current_balance' => -15000,
            'overdue_debt' => 5000,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.contractors.show', $contractor))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Contractors/Show')
                ->where('contractor.id', $contractor->id)
                ->where('contractor.partner.id', $this->partner->id)
                // JSON отдаёт круглые суммы целыми числами, поэтому сверяем
                // значение, а не его php-тип.
                ->where('contractor.balance.current', fn ($value) => (float) $value === -15000.0)
                ->where('contractor.balance.overdue', fn ($value) => (float) $value === 5000.0)
                ->etc());
    }

    #[Test]
    #[TestDox('Задача ставится на контрагента и попадает в ленту его партнёра')]
    public function task_on_contractor_lands_in_partner_feed(): void
    {
        $contractor = $this->contractorFor($this->partner);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), [
                'title' => 'Запросить акт сверки',
                'assignee_id' => $this->manager->id,
                'entity_type' => 'contractor',
                'entity_id' => $contractor->id,
            ])
            ->assertCreated();

        $task = CrmTask::query()->latest('id')->firstOrFail();

        $this->assertSame(Company::class, $task->related_type);
        $this->assertSame($contractor->id, (int) $task->related_id);
        // Денормализация партнёра — то, из-за чего задача видна в его ленте.
        $this->assertSame($this->partner->id, (int) $task->client_user_id);
    }

    #[Test]
    #[TestDox('Комментарий по контрагенту привязывается к партнёру')]
    public function comment_on_contractor_is_attached_to_partner(): void
    {
        $contractor = $this->contractorFor($this->partner);

        $this->actingAs($this->manager)
            ->postJson(route('crm.comments.store'), [
                'entity_type' => 'contractor',
                'entity_id' => $contractor->id,
                'body' => 'Реквизиты изменились, ждём новый договор',
            ])
            ->assertCreated();

        $comment = CrmComment::query()->latest('id')->firstOrFail();

        $this->assertSame(Company::class, $comment->commentable_type);
        $this->assertSame($this->partner->id, (int) $comment->client_user_id);
    }

    #[Test]
    #[TestDox('Задачу на чужого контрагента поставить нельзя')]
    public function task_on_foreign_contractor_is_rejected(): void
    {
        $foreign = $this->contractorFor($this->foreignPartner());

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), [
                'title' => 'Попытка дотянуться до чужого юрлица',
                'assignee_id' => $this->manager->id,
                'entity_type' => 'contractor',
                'entity_id' => $foreign->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('crm_tasks', 0);
    }

    #[Test]
    #[TestDox('Без права crm-contractors.view раздел и привязка закрыты')]
    public function section_is_gated_by_its_own_permission(): void
    {
        $contractor = $this->contractorFor($this->partner);

        $this->manager->revokePermissionTo('crm-contractors.view');
        // Право приходит ролью, поэтому забираем его у роли — иначе отзыв
        // у пользователя ничего не изменит.
        $this->manager->roles->first()->revokePermissionTo('crm-contractors.view');
        $this->manager->forgetCachedPermissions();

        $this->actingAs($this->manager)
            ->get(route('crm.contractors.index'))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), [
                'title' => 'Задача без права на раздел',
                'assignee_id' => $this->manager->id,
                'entity_type' => 'contractor',
                'entity_id' => $contractor->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Карточка партнёра отдаёт список его юрлиц')]
    public function partner_card_lists_its_contractors(): void
    {
        $first = $this->contractorFor($this->partner);
        $second = $this->contractorFor($this->partner);
        $foreign = $this->contractorFor($this->foreignPartner());

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->partner))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($first, $second, $foreign) {
                $ids = collect($page->toArray()['props']['contractors'])->pluck('id');

                $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids->all());
                $this->assertFalse($ids->contains($foreign->id));
            });
    }

    #[Test]
    #[TestDox('Старый адрес /crm/clients редиректит на /crm/partners')]
    public function legacy_client_urls_redirect_to_partners(): void
    {
        $this->actingAs($this->manager)
            ->get('/crm/clients')
            ->assertRedirect('/crm/partners');

        $this->actingAs($this->manager)
            ->get('/crm/clients/'.$this->partner->id)
            ->assertRedirect('/crm/partners/'.$this->partner->id);
    }
}
