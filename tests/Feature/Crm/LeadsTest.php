<?php

namespace Tests\Feature\Crm;

use App\Models\CrmLead;
use App\Models\CrmLeadStage;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\LeadFunnelService;
use App\Support\Crm\CrmEntityMap;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Лиды: сущность, воронка, метрики (crm-26 … crm-28).
 */
class LeadsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Стартовый набор стадий приезжает миграцией, чтобы раздел не открывался
        // пустой доской. Тестам он мешает: воронку здесь строит каждый тест сам.
        CrmLeadStage::query()->delete();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    private function stage(string $name, int $position, array $flags = []): CrmLeadStage
    {
        return CrmLeadStage::create(['name' => $name, 'position' => $position, ...$flags]);
    }

    /**
     * Минимум лида — имя и любой контакт. Требовать email значило бы не дать
     * завести того, у кого есть только телефон, — то есть провалить главное
     * требование раздела.
     */
    #[Test]
    public function лид_заводится_по_имени_и_одному_телефону(): void
    {
        $this->stage('Новый', 1);

        $this->actingAs($this->manager)
            ->postJson(route('crm.leads.store'), [
                'name' => 'Ольга с выставки',
                'phone' => '+7 900 000-00-00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ольга с выставки');

        $this->assertDatabaseHas('crm_leads', ['name' => 'Ольга с выставки']);
    }

    #[Test]
    public function лид_без_единого_контакта_не_сохраняется(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.leads.store'), ['name' => 'Кто-то'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Укажите хотя бы один контакт: телефон, email или мессенджер.');
    }

    #[Test]
    public function новый_лид_встаёт_на_первую_стадию_и_пишет_переход(): void
    {
        $first = $this->stage('Новый', 1);
        $this->stage('Квалификация', 2);

        $this->actingAs($this->manager)->postJson(route('crm.leads.store'), [
            'name' => 'Лид', 'phone' => '123',
        ])->assertCreated();

        $lead = CrmLead::query()->firstOrFail();

        $this->assertSame($first->id, $lead->stage_id);
        $this->assertDatabaseHas('crm_lead_stage_transitions', [
            'lead_id' => $lead->id,
            'from_stage_id' => null,
            'to_stage_id' => $first->id,
        ]);
    }

    #[Test]
    public function перемещение_по_доске_пишет_журнал_и_длительность_этапа(): void
    {
        $first = $this->stage('Новый', 1);
        $second = $this->stage('Квалификация', 2);

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));
        $lead = app(CrmLeadService::class)->create(
            ['name' => 'Лид', 'phone' => '123'],
            $this->manager,
        );

        $this->travelTo(Carbon::parse('2026-08-04 10:00:00'));
        $this->actingAs($this->manager)
            ->postJson(route('crm.leads.move', $lead), ['stage_id' => $second->id])
            ->assertOk();

        $this->assertDatabaseHas('crm_lead_stage_transitions', [
            'lead_id' => $lead->id,
            'from_stage_id' => $first->id,
            'to_stage_id' => $second->id,
            'previous_stage_hours' => 72,
        ]);
    }

    /**
     * Перетаскивание карточки обратно в ту же колонку не должно засорять
     * журнал и обнулять счётчик дней на этапе.
     */
    #[Test]
    public function перенос_в_ту_же_стадию_журнал_не_засоряет(): void
    {
        $first = $this->stage('Новый', 1);

        $lead = app(CrmLeadService::class)->create(['name' => 'Лид', 'phone' => '1'], $this->manager);

        $this->actingAs($this->manager)
            ->postJson(route('crm.leads.move', $lead), ['stage_id' => $first->id])
            ->assertOk();

        $this->assertSame(1, $lead->transitions()->count());
    }

    #[Test]
    public function стадию_с_лидами_удалить_нельзя(): void
    {
        $stage = $this->stage('Новый', 1);
        app(CrmLeadService::class)->create(['name' => 'Лид', 'phone' => '1'], $this->manager);

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->deleteJson(route('crm.lead-stages.destroy', $stage))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');
    }

    #[Test]
    public function состав_воронки_меняет_только_руководитель(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.lead-stages.store'), ['name' => 'Своя стадия'])
            ->assertForbidden();

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->postJson(route('crm.lead-stages.store'), ['name' => 'Своя стадия'])
            ->assertCreated();
    }

    /**
     * Неразобранный лид обязан кому-то попасться на глаза — в отличие
     * от партнёра без менеджера, которого мы намеренно прячем.
     */
    #[Test]
    public function ничей_лид_виден_даже_без_права_на_отдел(): void
    {
        $this->restrictManagersToOwnClients();
        $this->stage('Новый', 1);

        CrmLead::create(['name' => 'Ничей', 'phone' => '1', 'manager_id' => null]);

        $this->actingAs($this->manager)
            ->get(route('crm.leads.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 1));
    }

    #[Test]
    public function чужой_лид_без_права_на_отдел_не_виден(): void
    {
        $this->restrictManagersToOwnClients();
        $this->stage('Новый', 1);

        $foreignCard = PersonalManager::factory()->create();
        CrmLead::create(['name' => 'Чужой', 'phone' => '1', 'manager_id' => $foreignCard->id]);

        $this->actingAs($this->manager)
            ->get(route('crm.leads.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('leads', 0));
    }

    /**
     * Партнёра создаёт 1С, поэтому квалификация — привязка к появившемуся
     * партнёру, а не создание пользователя из CRM.
     */
    #[Test]
    public function квалификация_привязывает_лида_к_партнёру_и_ставит_выигрыш(): void
    {
        $this->stage('Новый', 1);
        $won = $this->stage('Выиграли', 2, ['is_won' => true]);

        $client = User::factory()->create(['personal_manager_id' => $this->card->id]);
        $lead = app(CrmLeadService::class)->create(['name' => 'Лид', 'phone' => '1'], $this->manager);

        $this->actingAs($this->manager)
            ->postJson(route('crm.leads.convert', $lead), ['user_id' => $client->id])
            ->assertOk();

        $lead->refresh();

        $this->assertSame($client->id, $lead->converted_user_id);
        $this->assertSame($won->id, $lead->stage_id);
    }

    #[Test]
    public function привязать_можно_только_к_видимому_партнёру(): void
    {
        $this->restrictManagersToOwnClients();
        $this->stage('Новый', 1);

        $foreignCard = PersonalManager::factory()->create();
        $foreignClient = User::factory()->create(['personal_manager_id' => $foreignCard->id]);
        $lead = app(CrmLeadService::class)->create(['name' => 'Лид', 'phone' => '1'], $this->manager);

        $this->actingAs($this->manager)
            ->postJson(route('crm.leads.convert', $lead), ['user_id' => $foreignClient->id])
            ->assertNotFound();
    }

    #[Test]
    public function лид_привязывается_к_задачам_и_комментариям_через_карту(): void
    {
        $this->assertContains(CrmEntityMap::LEAD, CrmEntityMap::types());
        $this->assertContains(CrmEntityMap::LEAD, CrmEntityMap::taskableTypes());
        $this->assertContains(CrmEntityMap::LEAD, CrmEntityMap::commentableTypes());
    }

    #[Test]
    public function стадию_можно_переименовать_перекрасить_и_переставить(): void
    {
        $stage = $this->stage('Новый', 1);

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->patchJson(route('crm.lead-stages.update', $stage), [
                'name' => 'Первый контакт',
                'color' => 'teal',
                'position' => 3,
            ])
            ->assertOk();

        $this->assertDatabaseHas('crm_lead_stages', [
            'id' => $stage->id,
            'name' => 'Первый контакт',
            'color' => 'teal',
            'position' => 3,
        ]);
    }

    /**
     * Такой лид попал бы в обе половины конверсии сразу, и сумма долей
     * перестала бы сходиться к целому.
     */
    #[Test]
    public function стадия_не_может_быть_и_выигрышной_и_проигрышной(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->postJson(route('crm.lead-stages.store'), [
                'name' => 'Странная',
                'is_won' => true,
                'is_lost' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.is_won.0', 'Стадия не может быть одновременно выигрышной и проигрышной.');
    }

    #[Test]
    public function менеджер_не_переименовывает_и_не_удаляет_стадии(): void
    {
        $stage = $this->stage('Новый', 1);

        $this->actingAs($this->manager)
            ->patchJson(route('crm.lead-stages.update', $stage), ['name' => 'Моё'])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.lead-stages.destroy', $stage))
            ->assertForbidden();
    }

    #[Test]
    public function скрытая_стадия_уходит_с_доски(): void
    {
        $visible = $this->stage('Новый', 1);
        $this->stage('Архив', 2, ['is_active' => false]);

        $this->actingAs($this->manager)
            ->get(route('crm.leads.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('stages', 1)
                ->where('stages.0.id', $visible->id));
    }

    /**
     * По двум переходам «средняя длительность этапа» — шум, а решения по ней
     * принимают всерьёз. Ниже порога наблюдений метрика не показывается.
     */
    #[Test]
    public function метрика_ниже_порога_наблюдений_скрыта(): void
    {
        $first = $this->stage('Новый', 1);
        $second = $this->stage('Квалификация', 2);

        foreach (range(1, 2) as $i) {
            $lead = app(CrmLeadService::class)->create(['name' => "Лид {$i}", 'phone' => '1'], $this->manager);
            app(CrmLeadService::class)->moveToStage($lead, $second, $this->manager);
        }

        $summary = app(LeadFunnelService::class)->summary($this->manager);
        $row = collect($summary['stages'])->firstWhere('stage_id', $first->id);

        $this->assertSame(2, $row['observations']);
        $this->assertFalse($row['reliable']);
        $this->assertNull($row['avg_hours']);
    }

    #[Test]
    public function конверсия_считается_по_флагам_стадии_а_не_по_позиции(): void
    {
        $this->stage('Новый', 1);
        $won = $this->stage('Выиграли', 2, ['is_won' => true]);
        $lost = $this->stage('Проиграли', 3, ['is_lost' => true]);
        // Стадия, добавленная в конец после «выиграли», метрику ломать не должна.
        $this->stage('Архив', 9);

        $a = app(CrmLeadService::class)->create(['name' => 'A', 'phone' => '1'], $this->manager);
        $b = app(CrmLeadService::class)->create(['name' => 'B', 'phone' => '1'], $this->manager);
        $c = app(CrmLeadService::class)->create(['name' => 'C', 'phone' => '1'], $this->manager);

        app(CrmLeadService::class)->moveToStage($a, $won, $this->manager);
        app(CrmLeadService::class)->moveToStage($b, $lost, $this->manager);
        // $c остаётся в работе и в конверсию не входит.

        $summary = app(LeadFunnelService::class)->summary($this->manager);

        $this->assertSame(1, $summary['conversion']['won']);
        $this->assertSame(1, $summary['conversion']['lost']);
        $this->assertSame(1, $summary['conversion']['open']);
        $this->assertSame(50.0, $summary['conversion']['percent']);
        $this->assertNotNull($c->fresh()->stage_id);
    }

    /**
     * «0 % конверсии» на пустой выборке читается как провал отдела, хотя
     * означает лишь, что воронку ещё никто не прошёл.
     */
    #[Test]
    public function конверсия_на_пустой_воронке_не_считается(): void
    {
        $this->stage('Новый', 1);
        app(CrmLeadService::class)->create(['name' => 'Лид', 'phone' => '1'], $this->manager);

        $summary = app(LeadFunnelService::class)->summary($this->manager);

        $this->assertNull($summary['conversion']['percent']);
    }

    /**
     * Задача на лиде показывалась в ленте как голый идентификатор и без ссылки:
     * `titleFor()`/`urlFor()` про лида не знали и падали в default.
     */
    #[Test]
    public function лид_в_ленте_подписан_именем_и_ведёт_на_доску(): void
    {
        $lead = CrmLead::factory()->create(['name' => 'Ольга', 'company_name' => 'Ромашка']);

        $described = CrmEntityMap::describe($lead);

        $this->assertSame('Лид', $described['label']);
        $this->assertSame('Ольга (Ромашка)', $described['title']);
        $this->assertStringContainsString('lead='.$lead->id, (string) $described['url']);
    }

    #[Test]
    public function диалог_задачи_ищет_лидов_но_не_показывает_чужих(): void
    {
        $this->restrictManagersToOwnClients();

        $mine = CrmLead::factory()->managedBy($this->card)->create(['name' => 'Мой лид']);
        $orphan = CrmLead::factory()->create(['name' => 'Ничей лид']);

        $stranger = PersonalManager::factory()->create();
        CrmLead::factory()->managedBy($stranger)->create(['name' => 'Чужой лид']);

        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.tasks.entities', ['type' => 'lead']))
            ->assertOk();

        $ids = array_column($response->json(), 'id');

        // Ничей лид виден намеренно: иначе неразобранный лид не попадёт никому.
        $this->assertEqualsCanonicalizing([$mine->id, $orphan->id], $ids);
    }

    /**
     * До появления ветки в резолвере скоуп лида не проверялся вовсе: правило
     * вырождалось в «есть crm-department.view», а оно есть у всех ролей продаж.
     */
    #[Test]
    public function комментарий_к_чужому_лиду_не_ставится(): void
    {
        $this->restrictManagersToOwnClients();

        $stranger = PersonalManager::factory()->create();
        $foreign = CrmLead::factory()->managedBy($stranger)->create();
        $mine = CrmLead::factory()->managedBy($this->card)->create();

        $this->actingAs($this->manager)->postJson(route('crm.comments.store'), [
            'entity_type' => 'lead',
            'entity_id' => $mine->id,
            'body' => 'Позвонил, ждёт счёт.',
        ])->assertCreated();

        $this->actingAs($this->manager)->postJson(route('crm.comments.store'), [
            'entity_type' => 'lead',
            'entity_id' => $foreign->id,
            'body' => 'Не должно пройти',
        ])->assertNotFound();
    }

    #[Test]
    public function менеджер_берёт_ничьего_лида_себе_но_не_отдаёт_коллеге(): void
    {
        $this->restrictManagersToOwnClients();

        $lead = CrmLead::factory()->create(['name' => 'Ничей', 'phone' => '1']);
        $colleague = PersonalManager::factory()->create();

        $this->actingAs($this->manager)->patchJson(route('crm.leads.update', $lead), [
            'name' => 'Ничей', 'phone' => '1', 'manager_id' => $this->card->id,
        ])->assertOk();

        $this->assertSame($this->card->id, $lead->fresh()->manager_id);

        $this->actingAs($this->manager)->patchJson(route('crm.leads.update', $lead), [
            'name' => 'Ничей', 'phone' => '1', 'manager_id' => $colleague->id,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.manager_id.0', 'Назначить лида другому менеджеру может только руководитель.');
    }

    /**
     * Выделение идёт по видимой странице, и один чужой лид в пачке не повод
     * отменять работу с остальными.
     */
    #[Test]
    public function массовый_перенос_пропускает_чужих_лидов(): void
    {
        $this->restrictManagersToOwnClients();

        $target = $this->stage('Переговоры', 2);
        $stranger = PersonalManager::factory()->create();

        $mine = CrmLead::factory()->managedBy($this->card)->create();
        $foreign = CrmLead::factory()->managedBy($stranger)->create();

        $this->actingAs($this->manager)->postJson(route('crm.leads.bulk'), [
            'ids' => [$mine->id, $foreign->id],
            'action' => 'move',
            'stage_id' => $target->id,
        ])->assertOk()->assertJsonPath('applied', 1);

        $this->assertSame($target->id, $mine->fresh()->stage_id);
        $this->assertNull($foreign->fresh()->stage_id);
        // Перенос идёт через сервис, поэтому журнал переходов не разъезжается с доской.
        $this->assertDatabaseHas('crm_lead_stage_transitions', [
            'lead_id' => $mine->id,
            'to_stage_id' => $target->id,
        ]);
    }

    #[Test]
    public function массовое_удаление_требует_права_на_удаление(): void
    {
        $lead = CrmLead::factory()->managedBy($this->card)->create();

        $this->actingAs($this->manager)->postJson(route('crm.leads.bulk'), [
            'ids' => [$lead->id],
            'action' => 'delete',
        ])->assertOk()->assertJsonPath('applied', 1);

        $this->assertSoftDeleted('crm_leads', ['id' => $lead->id]);

        // Правка лидов и их удаление — разные по цене ошибки, и право на вторую
        // строже: без него пачка не проходит, хотя маршрут открыт по crm-leads.edit.
        Role::findByName('sales-manager')->revokePermissionTo('crm-leads.delete');
        $this->manager->forgetCachedPermissions();

        $another = CrmLead::factory()->managedBy($this->card)->create();

        $this->actingAs($this->manager)->postJson(route('crm.leads.bulk'), [
            'ids' => [$another->id],
            'action' => 'delete',
        ])->assertForbidden();

        $this->assertDatabaseHas('crm_leads', ['id' => $another->id, 'deleted_at' => null]);
    }

    #[Test]
    public function таблица_фильтрует_залежавшихся_и_листается(): void
    {
        $stage = $this->stage('Квалификация', 1);

        CrmLead::factory()->managedBy($this->card)->onStage($stage)
            ->stagnantFor(CrmLead::STALE_DAYS + 5)->create(['name' => 'Забытый']);
        CrmLead::factory()->managedBy($this->card)->onStage($stage)->create(['name' => 'Свежий']);

        $this->actingAs($this->manager)
            ->get(route('crm.leads.index', ['view' => 'table', 'stale' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.view', 'table')
                ->has('rows.data', 1)
                ->where('rows.data.0.name', 'Забытый'));
    }

    #[Test]
    public function команда_ставит_задачу_по_залежавшемуся_лиду_один_раз(): void
    {
        $stage = $this->stage('Квалификация', 1);

        $lead = CrmLead::factory()->managedBy($this->card)->onStage($stage)
            ->stagnantFor(CrmLead::STALE_DAYS + 1)->create(['name' => 'Забытый']);

        $this->artisan('crm:leads-remind-stale')->assertSuccessful();

        $this->assertDatabaseHas('crm_tasks', [
            'related_type' => CrmLead::class,
            'related_id' => $lead->id,
            'assignee_id' => $this->manager->id,
        ]);

        // Второй прогон не должен плодить дубли — иначе список дел менеджера
        // за неделю зарастёт одной и той же задачей.
        $this->artisan('crm:leads-remind-stale')->assertSuccessful();

        $this->assertSame(1, CrmTask::query()
            ->where('related_type', CrmLead::class)
            ->where('related_id', $lead->id)
            ->count());
    }

    #[Test]
    public function свежий_и_ничей_лиды_задач_не_получают(): void
    {
        $stage = $this->stage('Квалификация', 1);

        CrmLead::factory()->managedBy($this->card)->onStage($stage)->create(['name' => 'Свежий']);
        CrmLead::factory()->onStage($stage)
            ->stagnantFor(CrmLead::STALE_DAYS + 3)->create(['name' => 'Ничей']);

        $this->artisan('crm:leads-remind-stale')->assertSuccessful();

        $this->assertSame(0, CrmTask::query()->where('related_type', CrmLead::class)->count());
    }

    /**
     * Выигранный лид стоит на месте по определению — напоминать по нему не о чем.
     */
    #[Test]
    public function по_выигранному_лиду_напоминаний_нет(): void
    {
        $won = $this->stage('Выиграли', 9, ['is_won' => true]);

        CrmLead::factory()->managedBy($this->card)->onStage($won)
            ->stagnantFor(CrmLead::STALE_DAYS + 10)->create();

        $this->artisan('crm:leads-remind-stale')->assertSuccessful();

        $this->assertSame(0, CrmTask::query()->where('related_type', CrmLead::class)->count());
    }
}
