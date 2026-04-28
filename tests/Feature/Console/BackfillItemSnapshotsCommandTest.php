<?php

namespace Tests\Feature\Console;

use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillItemSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $brand = Brand::create(['name' => 'BackfillBrand', 'slug' => 'backfill-brand']);
        $this->product = Product::factory()->create([
            'name' => 'Товар для бэкфилла',
            'brand_id' => $brand->id,
        ]);
    }

    #[Test]
    public function fills_empty_brand_snapshot_for_orders(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);

        // Вставка через DB::table в обход boot-хука. order_items.name NOT NULL —
        // на проде там есть исторические значения, которые backfill не должен трогать.
        $id = DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => 'Историческое имя',
            'brand_name_snapshot' => null,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cabinet-search:backfill-item-snapshots', ['--model' => 'order'])
            ->assertSuccessful();

        $row = DB::table('order_items')->where('id', $id)->first();
        $this->assertSame('Историческое имя', $row->name);
        $this->assertSame('BackfillBrand', $row->brand_name_snapshot);
    }

    #[Test]
    public function fills_empty_snapshot_fields_for_returns(): void
    {
        $return = ProductReturn::factory()->create(['user_id' => $this->user->id]);
        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);
        $shipmentItemId = DB::table('shipment_items')->insertGetId([
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'product_name_snapshot' => 'Не трогать',
            'brand_name_snapshot' => 'Не трогать',
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
            'total' => 100,
            'auto_discount_percent' => 0,
            'manual_discount_percent' => 0,
            'vat_rate' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('return_items')->insertGetId([
            'return_id' => $return->id,
            'shipment_item_id' => $shipmentItemId,
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'product_name_snapshot' => null,
            'brand_name_snapshot' => null,
            'quantity' => 1,
            'reason' => \App\Enums\ReturnReason::DEFECTIVE->value,
            'reason_comment' => null,
            'price' => 100,
            'subtotal' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cabinet-search:backfill-item-snapshots', ['--model' => 'return'])
            ->assertSuccessful();

        $row = DB::table('return_items')->where('id', $id)->first();
        $this->assertSame('Товар для бэкфилла', $row->product_name_snapshot);
        $this->assertSame('BackfillBrand', $row->brand_name_snapshot);
    }

    #[Test]
    public function fills_empty_snapshot_fields_for_shipments(): void
    {
        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);

        $id = DB::table('shipment_items')->insertGetId([
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'product_name_snapshot' => null,
            'brand_name_snapshot' => null,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
            'total' => 100,
            'auto_discount_percent' => 0,
            'manual_discount_percent' => 0,
            'vat_rate' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cabinet-search:backfill-item-snapshots', ['--model' => 'shipment'])
            ->assertSuccessful();

        $row = DB::table('shipment_items')->where('id', $id)->first();
        $this->assertSame('Товар для бэкфилла', $row->product_name_snapshot);
        $this->assertSame('BackfillBrand', $row->brand_name_snapshot);
    }

    #[Test]
    public function dry_run_does_not_modify_data(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);

        $id = DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => 'Историческое имя',
            'brand_name_snapshot' => null,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cabinet-search:backfill-item-snapshots', ['--model' => 'order', '--dry-run' => true])
            ->assertSuccessful();

        $row = DB::table('order_items')->where('id', $id)->first();
        $this->assertSame('Историческое имя', $row->name);
        $this->assertNull($row->brand_name_snapshot);
    }

    #[Test]
    public function skips_rows_with_already_filled_snapshot(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);

        $id = DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => 'Старое имя',
            'brand_name_snapshot' => 'Старый бренд',
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cabinet-search:backfill-item-snapshots', ['--model' => 'order'])
            ->assertSuccessful();

        $row = DB::table('order_items')->where('id', $id)->first();
        $this->assertSame('Старое имя', $row->name);
        $this->assertSame('Старый бренд', $row->brand_name_snapshot);
    }

    #[Test]
    public function rejects_unknown_model(): void
    {
        $this->artisan('cabinet-search:backfill-item-snapshots', ['--model' => 'invoice'])
            ->assertFailed();
    }

    #[Test]
    public function skips_rows_with_null_product_id(): void
    {
        // На проде ссылочная целостность гарантирует валидный product_id или NULL
        // (set null on delete). Backfill должен игнорировать строки без product_id.
        $order = Order::factory()->create(['user_id' => $this->user->id]);

        $id = DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => null,
            'name' => 'Сирота',
            'brand_name_snapshot' => null,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cabinet-search:backfill-item-snapshots', ['--model' => 'order'])
            ->assertSuccessful();

        $row = DB::table('order_items')->where('id', $id)->first();
        $this->assertSame('Сирота', $row->name);
        $this->assertNull($row->brand_name_snapshot);
    }
}
