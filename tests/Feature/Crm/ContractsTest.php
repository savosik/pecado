<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\ContractStatus;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCategory;
use App\Models\CrmTask;
use App\Models\Media;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Реестр договоров: список по вкладкам, карточка, скоуп менеджера,
 * вкладка «Без договора» и кабинет партнёра.
 */
class ContractsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    private Company $company;

    private ContractCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
        $this->company = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Ромашка ООО']);
        $this->category = ContractCategory::factory()->create(['name' => 'Тест: ООО']);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(string $route = 'crm.contracts.index', array $query = []): array
    {
        return $this->actingAs($this->manager)
            ->get(route($route, $query))
            ->viewData('page')['props'];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'number' => '№ 1-Т/2026',
            'company_id' => $this->company->id,
            'date' => '2026-01-15',
            'status' => ContractStatus::SENT->value,
            'payment_terms' => 'deferral',
            'form' => 'edo',
        ], $overrides);
    }

    #[Test]
    public function manager_sees_contracts_of_own_partners_grouped_by_category(): void
    {
        Contract::factory()->forCompany($this->company)->create(['category_id' => $this->category->id, 'number' => '№ 1']);
        $other = ContractCategory::factory()->create(['name' => 'Тест: ИП']);
        Contract::factory()->forCompany($this->company)->create(['category_id' => $other->id, 'number' => '№ 2']);

        $props = $this->props();

        $this->assertCount(2, $props['contracts']['data']);
        $counts = collect($props['categories'])->pluck('contracts_count', 'name');
        $this->assertSame(1, $counts['Тест: ООО']);
        $this->assertSame(1, $counts['Тест: ИП']);

        $filtered = $this->props('crm.contracts.index', ['category_id' => $other->id]);
        $this->assertCount(1, $filtered['contracts']['data']);
        $this->assertSame('№ 2', $filtered['contracts']['data'][0]['number']);
    }

    #[Test]
    public function foreign_contract_is_invisible_and_its_card_answers_404(): void
    {
        // 403 подтвердил бы менеджеру, что у чужого партнёра есть договор.
        $stranger = User::factory()->create();
        $stranger->assignRole('sales-manager');
        $foreignProfile = PersonalManager::factory()->create(['user_id' => $stranger->id]);
        $foreignClient = User::factory()->create(['personal_manager_id' => $foreignProfile->id]);
        $foreignCompany = Company::factory()->create(['user_id' => $foreignClient->id]);
        $foreign = Contract::factory()->forCompany($foreignCompany)->create(['category_id' => $this->category->id]);

        $this->assertCount(0, $this->props()['contracts']['data']);

        $this->actingAs($this->manager)
            ->get(route('crm.contracts.show', $foreign))
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->patchJson(route('crm.contracts.update', $foreign), $this->payload())
            ->assertNotFound();
    }

    #[Test]
    public function contract_without_partner_is_visible_only_to_department_viewers(): void
    {
        // Иностранный поставщик без юрлица в базе: у менеджера такого в скоупе нет,
        // иначе он всплывал бы у каждого.
        Contract::factory()->create(['category_id' => $this->category->id, 'counterparty_name' => 'Loma Inc.']);

        $this->assertCount(0, $this->props()['contracts']['data']);

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $props = $this->actingAs($head)
            ->get(route('crm.contracts.index'))
            ->viewData('page')['props'];

        $this->assertCount(1, $props['contracts']['data']);
        $this->assertSame('Loma Inc.', $props['contracts']['data'][0]['counterparty_name']);
    }

    #[Test]
    public function store_pulls_partner_from_contractor_and_returns_card(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson(route('crm.contracts.store'), $this->payload());

        $response->assertCreated()
            ->assertJsonPath('number', '№ 1-Т/2026')
            ->assertJsonPath('partner.id', $this->client->id)
            ->assertJsonPath('company.id', $this->company->id)
            ->assertJsonPath('status_label', 'Отправлен');

        $this->assertDatabaseHas('contracts', [
            'number' => '№ 1-Т/2026',
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'counterparty_name' => 'Ромашка ООО',
            'created_by_user_id' => $this->manager->id,
        ]);
    }

    #[Test]
    public function foreign_contractor_cannot_be_attached_to_a_contract(): void
    {
        $foreignCompany = Company::factory()->create();

        $this->actingAs($this->manager)
            ->postJson(route('crm.contracts.store'), $this->payload(['company_id' => $foreignCompany->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.company_id.0', 'Контрагент не найден.');
    }

    #[Test]
    public function validation_speaks_russian_and_guards_duplicates(): void
    {
        Contract::factory()->forCompany($this->company)->create(['category_id' => $this->category->id, 'number' => '№ 7']);

        $response = $this->actingAs($this->manager)
            ->postJson(route('crm.contracts.store'), $this->payload([
                'number' => '№ 7',
                'company_id' => null,
                'counterparty_name' => null,
                'valid_from' => '2026-05-01',
                'valid_until' => '2026-04-01',
            ]));

        $response->assertStatus(422);
        $this->assertSame('Договор с таким номером в этой категории уже есть.', $response->json('errors.number.0'));
        $this->assertSame('Выберите контрагента из базы или впишите название стороны.', $response->json('errors.counterparty_name.0'));
        $this->assertSame('Окончание действия не может быть раньше начала.', $response->json('errors.valid_until.0'));

        // Подписанный без даты подписания — недосмотр, который потом не восстановить.
        $this->actingAs($this->manager)
            ->postJson(route('crm.contracts.store'), $this->payload(['number' => '№ 8', 'status' => 'signed']))
            ->assertStatus(422)
            ->assertJsonPath('errors.signed_at.0', 'У подписанного договора укажите дату подписания.');
    }

    #[Test]
    public function tasks_and_comments_attach_to_a_contract(): void
    {
        $contract = Contract::factory()->forCompany($this->company)->create(['category_id' => $this->category->id]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), [
                'title' => 'Дожать подпись',
                'assignee_id' => $this->manager->id,
                'entity_type' => 'contract',
                'entity_id' => $contract->id,
            ])
            ->assertCreated();

        $task = CrmTask::query()->firstOrFail();
        $this->assertSame(Contract::class, $task->related_type);
        // Партнёр денормализуется с договора: задача попадёт в ленту партнёра.
        $this->assertSame($this->client->id, $task->client_user_id);

        $this->assertSame(1, $this->props()['contracts']['data'][0]['open_tasks_count']);
    }

    #[Test]
    public function missing_tab_lists_contractors_with_documents_but_without_contract(): void
    {
        $covered = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'С договором']);
        Contract::factory()->forCompany($covered)->create(['category_id' => $this->category->id]);
        Shipment::factory()->create(['user_id' => $this->client->id, 'company_id' => $covered->id, 'total_amount' => 100]);

        // Расторгнутый договор контрагента не закрывает.
        $terminated = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Расторгнут']);
        Contract::factory()->forCompany($terminated)->terminated()->create(['category_id' => $this->category->id]);
        Shipment::factory()->create(['user_id' => $this->client->id, 'company_id' => $terminated->id, 'total_amount' => 500]);

        // Только заказ — тоже повод, но жёлтый.
        $ordered = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Только заказ']);
        Order::factory()->create(['user_id' => $this->client->id, 'company_id' => $ordered->id]);

        // Без документов — не в списке: договор ей пока не нужен.
        Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Тишина']);

        $props = $this->props('crm.contracts.missing');
        $rows = collect($props['gaps']['data'])->keyBy('name');

        $this->assertSame(['Расторгнут', 'Только заказ'], $rows->keys()->sort()->values()->all());
        $this->assertSame('shipped', $rows['Расторгнут']['severity']);
        $this->assertSame(1, $rows['Расторгнут']['terminated_contracts_count']);
        $this->assertSame('ordered', $rows['Только заказ']['severity']);
        $this->assertSame(2, $props['missingCount']);

        $onlyShipped = $this->props('crm.contracts.missing', ['kind' => 'shipments']);
        $this->assertCount(1, $onlyShipped['gaps']['data']);
    }

    #[Test]
    public function filters_snapshot_survives_the_round_trip(): void
    {
        $props = $this->props('crm.contracts.index', [
            'status' => 'signed',
            'payment_terms' => 'deferral',
            'expiring' => 1,
            'search' => 'Ромашка',
        ]);

        $this->assertSame('signed', $props['filters']['status']);
        $this->assertSame('deferral', $props['filters']['payment_terms']);
        $this->assertSame(1, $props['filters']['expiring']);
        $this->assertSame('Ромашка', $props['filters']['search']);
    }

    #[Test]
    public function partner_sees_visible_contracts_of_own_companies_in_cabinet(): void
    {
        config(['contracts.cabinet_enabled' => true]);

        $visible = Contract::factory()->forCompany($this->company)->create(['category_id' => $this->category->id, 'number' => '№ видимый']);
        Contract::factory()->forCompany($this->company)->create(['category_id' => $this->category->id, 'number' => '№ скрытый', 'is_visible_in_cabinet' => false]);
        Contract::factory()->forCompany(Company::factory()->create())->create(['category_id' => $this->category->id, 'number' => '№ чужой']);

        $props = $this->actingAs($this->client)
            ->get(route('cabinet.contracts.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(['№ видимый'], collect($props['contracts'])->pluck('number')->all());
        $this->assertArrayNotHasKey('comment', $props['contracts'][0]);

        // Файл чужого договора — 404, а не 403.
        $this->actingAs($this->client)
            ->get(route('cabinet.contracts.download', [$visible->id + 100, 1]))
            ->assertNotFound();

        config(['contracts.cabinet_enabled' => false]);
        $this->actingAs($this->client)->get(route('cabinet.contracts.index'))->assertNotFound();
    }

    #[Test]
    public function categories_are_managed_by_editors_and_non_empty_ones_cannot_be_deleted(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->postJson(route('crm.contract-categories.store'), ['name' => 'Новая вкладка', 'sort_order' => 40])
            ->assertCreated()
            ->assertJsonPath('name', 'Новая вкладка');

        $this->actingAs($head)
            ->postJson(route('crm.contract-categories.store'), ['name' => 'ООО Пекадо'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Категория с таким названием уже есть.');

        Contract::factory()->forCompany($this->company)->create(['category_id' => $this->category->id]);

        $this->actingAs($head)
            ->deleteJson(route('crm.contract-categories.destroy', $this->category))
            ->assertStatus(422);

        // Менеджер без crm-contracts.delete категорию не удалит, а РОП пустую — удалит.
        $empty = ContractCategory::factory()->create();
        $this->actingAs($this->manager)->deleteJson(route('crm.contract-categories.destroy', $empty))->assertForbidden();
        $this->actingAs($head)->deleteJson(route('crm.contract-categories.destroy', $empty))->assertOk();
    }

    #[Test]
    public function quick_edit_changes_one_field_and_dates_the_signature(): void
    {
        // Из строки реестра меняют один статус — полная форма с обязательным
        // номером здесь мешала бы. Подписанный договор без даты получает сегодняшнюю.
        $contract = Contract::factory()->create([
            'category_id' => $this->category->id,
            'company_id' => $this->company->id,
            'user_id' => $this->client->id,
            'status' => 'sent',
            'signed_at' => null,
        ]);

        $this->actingAs($this->manager)
            ->patchJson(route('crm.contracts.quick', $contract), ['status' => 'signed'])
            ->assertOk()
            ->assertJsonPath('status_label', 'Подписан')
            ->assertJsonPath('signed_at', now()->format('d.m.Y'));

        $this->actingAs($this->manager)
            ->patchJson(route('crm.contracts.quick', $contract), ['payment_terms' => 'nonsense'])
            ->assertStatus(422);

        $this->actingAs($this->manager)
            ->patchJson(route('crm.contracts.quick', $contract), [])
            ->assertStatus(422);

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'signed']);
    }

    #[Test]
    public function without_permission_the_section_is_closed(): void
    {
        $role = \Spatie\Permission\Models\Role::findByName('sales-manager');
        $role->revokePermissionTo('crm-contracts.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->manager)->get(route('crm.contracts.index'))->assertForbidden();
    }

    #[Test]
    public function partner_sees_both_parties_but_not_drafts_and_folders(): void
    {
        config(['contracts.cabinet_enabled' => true]);

        $ours = Organization::factory()->create(['name' => 'ООО «Пекадо»', 'tax_id' => '7735195479']);
        $category = ContractCategory::factory()->create(['name' => 'Тест: ИП Елисеев (клиенты)', 'organization_id' => $ours->id]);

        Contract::factory()->forCompany($this->company)->create(['category_id' => $category->id, 'number' => '№ подписан', 'status' => ContractStatus::SIGNED]);
        Contract::factory()->forCompany($this->company)->create(['category_id' => $category->id, 'number' => '№ отправлен', 'status' => ContractStatus::SENT]);
        Contract::factory()->forCompany($this->company)->create(['category_id' => $category->id, 'number' => '№ черновик', 'status' => ContractStatus::DRAFT]);

        $props = $this->actingAs($this->client)
            ->get(route('cabinet.contracts.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $rows = collect($props['contracts']);

        // Черновик («не отправлен») — документа у партнёра ещё нет.
        $this->assertEqualsCanonicalizing(['№ подписан', '№ отправлен'], $rows->pluck('number')->all());

        // Папка реестра — внутренняя кухня, наша сторона — обязательна.
        $this->assertArrayNotHasKey('category', $rows->first());
        $this->assertSame('ООО «Пекадо»', $rows->first()['organization']['name']);
        $this->assertSame('7735195479', $rows->first()['organization']['tax_id']);
        $this->assertSame('Ромашка ООО', $rows->first()['company']['name']);
    }

    #[Test]
    public function our_organization_defaults_to_the_category_one_and_can_be_overridden(): void
    {
        $pecado = Organization::factory()->create(['name' => 'ООО «Пекадо»']);
        $eliseev = Organization::factory()->create(['name' => 'ИП Елисеев П.А.']);
        $this->category->update(['organization_id' => $pecado->id]);

        $byCategory = $this->actingAs($this->manager)
            ->postJson(route('crm.contracts.store'), $this->payload())
            ->assertCreated()
            ->json();
        $this->assertSame('ООО «Пекадо»', $byCategory['organization']['name']);

        $explicit = $this->actingAs($this->manager)
            ->postJson(route('crm.contracts.store'), $this->payload(['number' => '№ 2-Т/2026', 'organization_id' => $eliseev->id]))
            ->assertCreated()
            ->json();
        $this->assertSame('ИП Елисеев П.А.', $explicit['organization']['name']);

        $this->actingAs($this->manager)
            ->postJson(route('crm.contracts.store'), $this->payload(['number' => '№ 3-Т/2026', 'organization_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);
    }

    #[Test]
    public function assign_command_fills_organization_from_categories(): void
    {
        $pecado = Organization::factory()->create(['name' => 'ООО «Пекадо»']);
        $linked = ContractCategory::factory()->create(['name' => 'Тест: ООО Пекадо', 'organization_id' => $pecado->id]);
        $orphan = ContractCategory::factory()->create(['name' => 'Тест: ИП Кербер', 'organization_id' => null]);

        $a = Contract::factory()->forCompany($this->company)->create(['category_id' => $linked->id]);
        $b = Contract::factory()->forCompany($this->company)->create(['category_id' => $orphan->id]);
        Contract::query()->whereKey($a->id)->update(['organization_id' => null]);

        $this->artisan('crm:contracts-assign-organizations', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($a->fresh()->organization_id);

        $this->artisan('crm:contracts-assign-organizations')->assertSuccessful();
        $this->assertSame($pecado->id, $a->fresh()->organization_id);
        $this->assertNull($b->fresh()->organization_id);
    }

    #[Test]
    public function contract_scans_live_on_the_private_disk_and_stay_out_of_the_media_library(): void
    {
        Storage::fake(config('media-library.disk_name'));
        Storage::fake(Contract::attachmentsDisk());

        $contract = Contract::factory()->forCompany($this->company)->create(['category_id' => $this->category->id]);

        $mediaId = $this->actingAs($this->manager)
            ->postJson(route('crm.attachments.store'), [
                'entity_type' => 'contract',
                'entity_id' => $contract->id,
                'file' => UploadedFile::fake()->image('скан.jpg'),
            ])
            ->assertCreated()
            ->json('id');

        $media = Media::query()->findOrFail($mediaId);
        $this->assertSame(Contract::attachmentsDisk(), $media->disk);
        Storage::disk(Contract::attachmentsDisk())->assertExists($media->getPathRelativeToRoot());
        Storage::disk(config('media-library.disk_name'))->assertMissing($media->getPathRelativeToRoot());
        $this->assertFalse($media->shouldBeSearchable());
        $this->assertSame(0, Media::query()->library()->count());

        // Партнёр скачивает скан через контроллер, а не по публичной ссылке.
        config(['contracts.cabinet_enabled' => true]);
        $this->actingAs($this->client)
            ->get(route('cabinet.contracts.download', [$contract->id, $media->id]))
            ->assertOk();
    }

    #[Test]
    public function private_storage_command_moves_old_scans_from_the_public_disk(): void
    {
        $public = config('media-library.disk_name');
        Storage::fake($public);
        Storage::fake(Contract::attachmentsDisk());

        $contract = Contract::factory()->forCompany($this->company)->create(['category_id' => $this->category->id]);
        $media = $contract->addMedia(UploadedFile::fake()->image('старый-скан.jpg'))
            ->toMediaCollection(\App\Support\Crm\CrmAttachments::COLLECTION, $public);
        $path = $media->getPathRelativeToRoot();
        Storage::disk($public)->assertExists($path);

        $this->artisan('crm:contracts-private-storage')->assertSuccessful();

        $this->assertSame(Contract::attachmentsDisk(), $media->fresh()->disk);
        Storage::disk(Contract::attachmentsDisk())->assertExists($path);
        Storage::disk($public)->assertMissing($path);
    }
}
