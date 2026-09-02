<?php

namespace Tests\Feature\Defect;

use App\Enums\OrderType;
use App\Enums\UserStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Витрина: данные уценки на карточке товара (showJson = тот же buildProductShowData).
 */
class ProductShowDefectsTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(): User
    {
        return User::factory()->create(['status' => UserStatus::ACTIVE]);
    }

    private function reserve(ProductDefect $defect, int $quantity): void
    {
        $order = Order::factory()->create(['type' => OrderType::DEFECT]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $defect->product_id,
            'product_defect_id' => $defect->id,
            'name' => 'Уценка',
            'price' => $defect->price,
            'base_price' => $defect->price,
            'discount_percent' => 0,
            'final_price' => $defect->price,
            'quantity' => $quantity,
            'subtotal' => $defect->price * $quantity,
        ]);
    }

    public function test_sellable_defect_appears_on_card_for_active_user(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $defect = ProductDefect::factory()->for($product)->sellable(499)->create(['quantity' => 3]);
        $defect->addMedia(UploadedFile::fake()->image('d.jpg'))->toMediaCollection(ProductDefect::MEDIA_COLLECTION);

        $response = $this->actingAs($this->activeUser())
            ->getJson("/api/products/{$product->slug}")
            ->assertOk();

        $this->assertTrue($response->json('product.has_defects'));
        $this->assertEquals(499.0, $response->json('product.defect_min_price'));
        $this->assertCount(1, $response->json('defects'));
        $this->assertEquals(499.0, $response->json('defects.0.price'));
        $this->assertSame(3, $response->json('defects.0.available_quantity'));
        $this->assertCount(1, $response->json('defects.0.photos'));
    }

    public function test_defect_available_quantity_reflects_reservation(): void
    {
        $product = Product::factory()->create();
        $defect = ProductDefect::factory()->for($product)->sellable(300)->create(['quantity' => 5]);
        $this->reserve($defect, 2);

        $response = $this->actingAs($this->activeUser())
            ->getJson("/api/products/{$product->slug}")
            ->assertOk();

        $this->assertSame(3, $response->json('defects.0.available_quantity'));
    }

    public function test_fully_reserved_defect_hides_from_card(): void
    {
        $product = Product::factory()->create();
        $defect = ProductDefect::factory()->for($product)->sellable(300)->create(['quantity' => 1]);
        $this->reserve($defect, 1);

        $response = $this->actingAs($this->activeUser())
            ->getJson("/api/products/{$product->slug}")
            ->assertOk();

        $this->assertFalse($response->json('product.has_defects'));
        $this->assertCount(0, $response->json('defects'));
    }

    public function test_unpublished_defect_is_not_shown(): void
    {
        $product = Product::factory()->create();
        ProductDefect::factory()->for($product)->priced(300)->create(); // без публикации

        $response = $this->actingAs($this->activeUser())
            ->getJson("/api/products/{$product->slug}")
            ->assertOk();

        $this->assertFalse($response->json('product.has_defects'));
        $this->assertCount(0, $response->json('defects'));
    }

    public function test_guest_sees_flag_but_not_prices(): void
    {
        // Значок/бейдж (has_defects) виден всем, но список партий с ценами — нет.
        $product = Product::factory()->create();
        ProductDefect::factory()->for($product)->sellable(300)->create(['quantity' => 2]);

        $response = $this->getJson("/api/products/{$product->slug}")->assertOk();

        $this->assertTrue($response->json('product.has_defects'));
        $this->assertNull($response->json('product.defect_min_price'), 'Гость не должен видеть минимальную цену уценки');
        $this->assertCount(0, $response->json('defects'), 'Гость не должен получать цены партий');
    }

    public function test_defect_min_price_takes_cheapest_available_batch(): void
    {
        $product = Product::factory()->create();
        ProductDefect::factory()->for($product)->sellable(500)->create(['quantity' => 2]);
        ProductDefect::factory()->for($product)->sellable(350)->create(['quantity' => 1]);

        // Самая дешёвая партия полностью выбрана — минимум по ней не считается.
        $taken = ProductDefect::factory()->for($product)->sellable(100)->create(['quantity' => 1]);
        $this->reserve($taken, 1);

        $response = $this->actingAs($this->activeUser())
            ->getJson("/api/products/{$product->slug}")
            ->assertOk();

        $this->assertEquals(350.0, $response->json('product.defect_min_price'));
    }

    public function test_catalog_list_marks_products_with_defects(): void
    {
        $withDefect = Product::factory()->create();
        $withoutDefect = Product::factory()->create();
        ProductDefect::factory()->for($withDefect)->sellable(200)->create(['quantity' => 1]);

        $response = $this->actingAs($this->activeUser())
            ->getJson('/api/catalog/products')
            ->assertOk();

        $flags = collect($response->json('data'))->pluck('has_defects', 'id');

        $this->assertTrue($flags[$withDefect->id]);
        $this->assertFalse($flags[$withoutDefect->id]);

        $prices = collect($response->json('data'))->pluck('defect_min_price', 'id');

        $this->assertEquals(200.0, $prices[$withDefect->id]);
        $this->assertNull($prices[$withoutDefect->id]);
    }

    public function test_guest_catalog_hides_defect_min_price(): void
    {
        $product = Product::factory()->create();
        ProductDefect::factory()->for($product)->sellable(200)->create(['quantity' => 1]);

        $response = $this->getJson('/api/catalog/products')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $product->id);

        $this->assertTrue($row['has_defects']);
        $this->assertArrayNotHasKey('defect_min_price', $row, 'Гостю ценовое поле вырезается вместе с остальными ценами');
    }
}
