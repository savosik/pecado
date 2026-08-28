<?php

namespace Tests\Feature\Crm;

use App\Models\Order;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Организация и склад документов в CRM: лента, вкладки «Заказы»/«Реализации»,
 * карточка документа и фильтр (эпик org-00, витрина волны 1).
 *
 * Менеджер сверяет с клиентом накладную, а она выписана конкретным нашим юрлицом
 * и уехала с конкретного склада — поэтому эти два поля должны читаться там же, где
 * менеджер работает, а не только в админке.
 */
class CrmDocumentOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config(['erp.organizations.enabled' => true]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $card->id]);
    }

    private function orderFor(array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $this->client->id,
            'erp_number' => 'ЗК-100',
            'erp_created_at' => now(),
        ], $attributes));
    }

    private function shipmentFor(array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->client->id,
            'erp_number' => 'РЕ-200',
            'date' => now()->toDateString(),
            'erp_created_at' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 8000,
        ], $attributes));
    }

    // ──────────────────────────────────────────────
    // Карточка документа
    // ──────────────────────────────────────────────

    #[Test]
    public function order_card_shows_organization_and_warehouse(): void
    {
        $order = $this->orderFor([
            'organization_id' => Organization::factory()->create(['name' => 'ООО Пекадо'])->id,
            'warehouse_id' => Warehouse::factory()->create(['name' => 'Москва основной'])->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.organization.name', 'ООО Пекадо')
                ->where('document.organization.is_stub', false)
                ->where('document.warehouse', 'Москва основной')
            );
    }

    #[Test]
    public function shipment_card_shows_organization_and_warehouse(): void
    {
        $shipment = $this->shipmentFor([
            'organization_id' => Organization::factory()->create(['name' => 'ООО Пекадо'])->id,
            'warehouse_id' => Warehouse::factory()->create(['name' => 'Тюмень Основной'])->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.shipments.show', $shipment))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.organization.name', 'ООО Пекадо')
                ->where('document.warehouse', 'Тюмень Основной')
            );
    }

    /**
     * Реализация внутреннего юрлица («Реклама») в регистр взаиморасчётов не
     * попадает, и «не оплачена» для неё — ложь: долга нет и не будет. Менеджер
     * видит «Вне взаиморасчётов» без сумм «оплачено/остаток».
     */
    #[Test]
    public function shipment_of_internal_organization_is_marked_out_of_settlements(): void
    {
        $shipment = $this->shipmentFor([
            'organization_id' => Organization::factory()->create([
                'name' => 'Реклама',
                'is_settlements_excluded' => true,
            ])->id,
            'payment_status' => Shipment::PAYMENT_UNPAID,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.shipments.show', $shipment))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.payment_summary.status', Shipment::PAYMENT_EXCLUDED)
                ->where('document.payment_summary.status_label', 'Вне взаиморасчётов')
                ->where('document.payment_summary.paid_label', null)
                ->where('document.payment_summary.unpaid_label', null)
            );

        $regular = $this->shipmentFor(['payment_status' => Shipment::PAYMENT_UNPAID]);

        $this->actingAs($this->manager)
            ->get(route('crm.shipments.show', $regular))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.payment_summary.status', Shipment::PAYMENT_UNPAID)
                ->where('document.payment_summary.status_label', 'Не оплачена')
            );
    }

    /**
     * У заглушки вместо названия лежит UUID — менеджер должен видеть, что юрлицо
     * ещё не заведено, а не гадать над строкой из тридцати шести символов.
     */
    #[Test]
    public function stub_organization_is_marked_in_document_card(): void
    {
        $order = $this->orderFor([
            'organization_id' => Organization::factory()->stub()->create()->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.organization.is_stub', true)
            );
    }

    #[Test]
    public function document_card_hides_organization_when_flag_disabled(): void
    {
        config(['erp.organizations.enabled' => false]);

        $order = $this->orderFor([
            'organization_id' => Organization::factory()->create()->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.organization', null)
                ->where('document.warehouse', null)
            );
    }

    // ──────────────────────────────────────────────
    // Лента и вкладки документов
    // ──────────────────────────────────────────────

    #[Test]
    public function timeline_entries_carry_organization_and_warehouse(): void
    {
        $this->orderFor([
            'organization_id' => Organization::factory()->create(['name' => 'ООО Пекадо'])->id,
            'warehouse_id' => Warehouse::factory()->create(['name' => 'Москва основной'])->id,
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['order']]))
            ->assertOk()
            ->assertJsonPath('data.0.organization.name', 'ООО Пекадо')
            ->assertJsonPath('data.0.organization.is_stub', false)
            ->assertJsonPath('data.0.warehouse', 'Москва основной');
    }

    #[Test]
    public function timeline_hides_organization_when_flag_disabled(): void
    {
        config(['erp.organizations.enabled' => false]);

        $this->orderFor([
            'organization_id' => Organization::factory()->create()->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['order']]))
            ->assertOk()
            ->assertJsonPath('data.0.organization', null)
            ->assertJsonPath('data.0.warehouse', null);
    }

    #[Test]
    public function documents_tab_filters_by_organization(): void
    {
        $organization = Organization::factory()->create();

        $matching = $this->orderFor(['organization_id' => $organization->id]);
        $this->orderFor(['erp_number' => 'ЗК-101']);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [
                $this->client,
                'types' => ['order'],
                'organization_id' => (string) $organization->id,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    /**
     * «Не указана» — рабочий фильтр переходного периода: документов без организации
     * пока большинство, и отобрать нужно именно их.
     */
    #[Test]
    public function documents_tab_filters_documents_without_organization(): void
    {
        $this->orderFor(['organization_id' => Organization::factory()->create()->id]);
        $without = $this->orderFor(['erp_number' => 'ЗК-101']);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [
                $this->client,
                'types' => ['order'],
                'organization_id' => 'none',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $without->id);
    }

    /**
     * У комментария, задачи и письма организации нет — при фильтре по ней они
     * уходят из ленты целиком, а не показываются «заодно».
     */
    #[Test]
    public function organization_filter_drops_manager_records_from_feed(): void
    {
        $organization = Organization::factory()->create();
        $this->orderFor(['organization_id' => $organization->id]);

        \App\Models\CrmComment::create([
            'user_id' => $this->manager->id,
            'commentable_type' => User::class,
            'commentable_id' => $this->client->id,
            'body' => 'Договорились созвониться',
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [
                $this->client,
                'organization_id' => (string) $organization->id,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'order');
    }

    #[Test]
    public function client_card_provides_organizations_for_filter(): void
    {
        Organization::factory()->create(['name' => 'ООО Пекадо']);
        // Заглушку в фильтр не предлагаем: выбирать «юрлицо-UUID» менеджеру незачем.
        Organization::factory()->stub()->create();

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('organizationsEnabled', true)
                ->has('organizations', 1)
                ->where('organizations.0.name', 'ООО Пекадо')
            );
    }
}
