<?php

namespace Tests\Feature\Cabinet;

use App\Enums\UserStatus;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PrintedDocument;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Services\Crm\Mail\Sources\DocumentOccasions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Документы внутренних юрлиц («Реклама») клиенту не показываются.
 *
 * Регистр взаиморасчётов такие движения отбрасывает на входе
 * (`organizations.is_settlements_excluded`, см. SettlementExcludedOrganizationTest),
 * но реализации, печатные формы и платежи сайт хранит — они нужны менеджеру.
 * Клиент же не должен видеть ни реализацию «Рекламы» со статусом «не оплачена»,
 * ни её счёт, ни акт сверки: это не его расчёты. Граница проходит на выдаче —
 * кабинет, клиентское API, письма о документах, аналитика.
 */
class InternalOrganizationHiddenFromClientTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Organization $regular;

    private Organization $internal;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('printed-documents');

        config([
            'documents.enabled' => true,
            'documents.disk' => 'printed-documents',
            'search-cabinet.export' => true,
            'cabinet.finance_enabled' => true,
            'erp.organizations.enabled' => true,
        ]);

        $this->user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'must_change_password' => false,
        ]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);

        $this->regular = Organization::factory()->create(['name' => 'ООО «Пекадо»']);
        $this->internal = Organization::factory()->create([
            'name' => 'Реклама',
            'is_settlements_excluded' => true,
        ]);
    }

    private function shipment(Organization $organization, array $attributes = []): Shipment
    {
        $orderUuid = $attributes['order_uuid'] ?? null;
        unset($attributes['order_uuid']);

        $shipment = Shipment::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $organization->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 1000,
        ], $attributes));

        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'order_uuid' => $orderUuid,
            'quantity' => 1,
            'price' => 1000,
            'subtotal' => 1000,
            'total' => 1000,
        ]);

        return $shipment;
    }

    private function document(Organization $organization): PrintedDocument
    {
        $document = PrintedDocument::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $organization->id,
        ]);
        Storage::disk('printed-documents')->put($document->path, '%PDF-1.7 тест');

        return $document;
    }

    /** @return list<int> */
    private function idsOf(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    #[Test]
    public function shipments_list_card_and_exports_hide_internal_organization(): void
    {
        $visible = $this->shipment($this->regular, ['number' => 'SH-VISIBLE']);
        $hidden = $this->shipment($this->internal, ['number' => 'SH-ADVERT']);
        // Без организации — обычная реализация, ей нечего исключать.
        $unassigned = $this->shipment($this->regular, ['organization_id' => null, 'number' => 'SH-NOORG']);

        $this->actingAs($this->user)->get('/cabinet/shipments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shipments.data', fn ($rows) => $this->idsOf($rows->toArray()) == [$visible->id, $unassigned->id]
                    || $this->idsOf($rows->toArray()) == [$unassigned->id, $visible->id]));

        $this->actingAs($this->user)->get("/cabinet/shipments/{$visible->id}")->assertOk();
        $this->actingAs($this->user)->get("/cabinet/shipments/{$hidden->id}")->assertNotFound();
        $this->actingAs($this->user)->get("/cabinet/shipments/{$hidden->id}/items/export")->assertNotFound();

        $csv = $this->actingAs($this->user)->get('/cabinet/shipments/export?format=csv')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('SH-VISIBLE', $csv);
        $this->assertStringNotContainsString('SH-ADVERT', $csv);
    }

    #[Test]
    public function order_card_lists_only_shipments_of_regular_organizations(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $visible = $this->shipment($this->regular, ['order_uuid' => $order->uuid]);
        $this->shipment($this->internal, ['order_uuid' => $order->uuid]);

        $this->actingAs($this->user)->get("/cabinet/orders/{$order->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.shipments', fn ($rows) => $this->idsOf($rows->toArray()) === [$visible->id]));
    }

    #[Test]
    public function return_form_does_not_offer_internal_shipments(): void
    {
        $visible = $this->shipment($this->regular);
        $hidden = $this->shipment($this->internal);

        $found = $this->actingAs($this->user)
            ->getJson('/cabinet/returns/search-shipments?query=')
            ->assertOk()
            ->json();

        $this->assertSame([$visible->id], $this->idsOf($found));

        $this->actingAs($this->user)
            ->getJson("/cabinet/returns/shipment-items?shipment_id={$hidden->id}")
            ->assertNotFound();
    }

    #[Test]
    public function client_api_hides_internal_shipments(): void
    {
        $token = ApiToken::create([
            'user_id' => $this->user->id,
            'name' => 'test',
            'token' => 'test-token-internal-org',
            'is_active' => true,
        ])->token;

        $visible = $this->shipment($this->regular);
        $hidden = $this->shipment($this->internal, ['erp_number' => '29УТ-777777']);

        $this->getJson("/api/client-api/{$token}/shipments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);

        $this->getJson("/api/client-api/{$token}/shipments/{$hidden->id}")->assertNotFound();
        $this->getJson("/api/client-api/{$token}/shipments/29УТ-777777")->assertNotFound();
    }

    #[Test]
    public function documents_of_internal_organization_are_hidden_from_client(): void
    {
        $visible = $this->document($this->regular);
        $hidden = $this->document($this->internal);

        $this->actingAs($this->user)->get('/cabinet/documents')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('documents.data', fn ($rows) => $this->idsOf($rows->toArray()) === [$visible->id])
                // Фильтр по продавцу тоже не должен предлагать «Рекламу».
                ->where('organizations', fn ($options) => collect($options)->pluck('label')->all() === ['ООО «Пекадо»']));

        $this->actingAs($this->user)->get("/cabinet/documents/{$visible->id}/download")->assertOk();
        $this->actingAs($this->user)->get("/cabinet/documents/{$hidden->id}/download")->assertNotFound();
    }

    #[Test]
    public function document_notification_is_not_captured_for_internal_organization(): void
    {
        $regular = $this->document($this->regular);
        $internal = $this->document($this->internal);

        $stream = $this->mock(MailStream::class);
        $stream->shouldReceive('captureQuietly')
            ->once()
            ->withArgs(fn ($occasion) => $occasion->subject?->is($regular));

        $occasions = app(DocumentOccasions::class);
        $occasions->published($regular);
        $occasions->published($internal);
    }

    #[Test]
    public function payments_of_internal_organization_are_hidden_from_client(): void
    {
        $visible = Payment::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->regular->id,
        ]);
        $hidden = Payment::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->internal->id,
        ]);

        $this->actingAs($this->user)->get('/cabinet/payments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('payments.data', fn ($rows) => $this->idsOf($rows->toArray()) === [$visible->id]));

        $this->actingAs($this->user)->get("/cabinet/payments/{$visible->id}")->assertOk();
        $this->actingAs($this->user)->get("/cabinet/payments/{$hidden->id}")->assertNotFound();
    }

    #[Test]
    public function cabinet_analytics_does_not_count_internal_shipments(): void
    {
        $this->shipment($this->regular);
        $this->shipment($this->internal, ['total_amount' => 2785.80]);

        $this->actingAs($this->user)->getJson('/cabinet/analytics/data')
            ->assertOk()
            ->assertJsonPath('metrics.shipments_count', 1)
            ->assertJsonPath('metrics.total_amount', 1000);
    }
}
