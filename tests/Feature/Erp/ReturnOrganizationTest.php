<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Erp\Handlers\HandleReturnUpdated;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Организация возврата — справочно, выводится с реализаций-оснований
 * (v15.8.0, карточка org-08).
 *
 * В 1С организация возврата НЕ отправляется: она выводит её из тех же оснований.
 */
class ReturnOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
        $this->product = Product::factory()->create();
    }

    private function shipmentItem(?Organization $organization): ShipmentItem
    {
        $shipment = Shipment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $organization?->id,
            'number' => '29УТ-'.fake()->unique()->numerify('######'),
            'date' => '2026-08-01',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 200,
        ]);

        return ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'price' => 100,
            'total' => 500,
            'subtotal' => 500,
        ]);
    }

    private function createReturn(array $shipmentItems): ProductReturn
    {
        return app(ReturnService::class)->createForUser($this->user, [
            'comment' => 'Возврат по акту',
            'items' => array_map(fn (ShipmentItem $si) => [
                'shipment_item_id' => $si->id,
                'quantity' => 1,
                'reason' => 'defective',
            ], $shipmentItems),
        ]);
    }

    #[Test]
    public function return_takes_organization_from_its_shipments(): void
    {
        $organization = Organization::factory()->create();

        $return = $this->createReturn([
            $this->shipmentItem($organization),
            $this->shipmentItem($organization),
        ]);

        $this->assertSame($organization->id, $return->organization_id);
    }

    /**
     * Основания разных юрлиц — организация не определена. «Первую попавшуюся»
     * брать нельзя: это была бы выдуманная цифра в отчётах.
     */
    #[Test]
    public function mixed_organizations_leave_field_null_without_splitting_return(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();

        $return = $this->createReturn([
            $this->shipmentItem($first),
            $this->shipmentItem($second),
        ]);

        $this->assertNull($return->organization_id);
        $this->assertCount(2, $return->items);
        $this->assertSame(1, ProductReturn::count(), 'Возврат не должен дробиться по организациям');
    }

    #[Test]
    public function legacy_shipment_without_organization_gives_null(): void
    {
        $return = $this->createReturn([$this->shipmentItem(null)]);

        $this->assertNull($return->organization_id);
    }

    /**
     * Смешение legacy-реализации и реализации с организацией — тоже NULL:
     * «протаскивать» организацию на позиции, к которым она не относится, нельзя.
     */
    #[Test]
    public function mix_of_legacy_and_known_organization_gives_null(): void
    {
        $organization = Organization::factory()->create();

        $return = $this->createReturn([
            $this->shipmentItem($organization),
            $this->shipmentItem(null),
        ]);

        $this->assertNull($return->organization_id);
    }

    #[Test]
    public function return_updated_from_erp_overrides_derived_organization(): void
    {
        $derived = Organization::factory()->create();
        $fromErp = Organization::factory()->create(['external_id' => '9da1768a-40d4-11e1-a692-001e6711ed1d']);

        $return = $this->createReturn([$this->shipmentItem($derived)]);
        $this->assertSame($derived->id, $return->organization_id);

        app(HandleReturnUpdated::class)->handle([
            'event' => 'return.updated',
            'message_id' => 'msg-return-org',
            'uuid' => $return->uuid,
            'status' => 'for_return',
            'organization' => ['uuid' => $fromErp->external_id],
        ]);

        $this->assertSame($fromErp->id, $return->fresh()->organization_id);
    }

    #[Test]
    public function return_updated_without_organization_keeps_derived_one(): void
    {
        $derived = Organization::factory()->create();
        $return = $this->createReturn([$this->shipmentItem($derived)]);

        app(HandleReturnUpdated::class)->handle([
            'event' => 'return.updated',
            'message_id' => 'msg-return-no-org',
            'uuid' => $return->uuid,
            'status' => 'for_return',
        ]);

        $this->assertSame($derived->id, $return->fresh()->organization_id);
    }

    /**
     * Реализация удалена после создания возврата — организация остаётся снапшотом,
     * пересчитывать её не нужно.
     */
    #[Test]
    public function organization_survives_deletion_of_source_shipment(): void
    {
        $organization = Organization::factory()->create();
        $item = $this->shipmentItem($organization);
        $return = $this->createReturn([$item]);

        $item->shipment->delete();

        $this->assertSame($organization->id, $return->fresh()->organization_id);
    }
}
