<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Товары сертификата «в столбик»: штрихкоды, артикулы и коды 1С распознаются
 * так же, как в «Импорте заказа» корзины, и возвращаются в форме ProductSelector.
 */
class CertificateResolveProductsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    #[Test]
    public function распознаёт_артикул_код_и_штрихкоды_и_возвращает_нераспознанные(): void
    {
        $bySku = Product::factory()->create(['sku' => 'LE-13']);
        $byCode = Product::factory()->create(['code' => '00-00012345']);
        $byBarcode = Product::factory()->create(['barcode' => '4601234567890']);
        $byExtraBarcode = Product::factory()->create();
        ProductBarcode::create(['product_id' => $byExtraBarcode->id, 'barcode' => '4609999999999']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.certificates.resolve-products'), [
                'identifiers' => ['le-13', '00-00012345', '4601234567890', '4609999999999', 'LE-13', 'НЕТ-ТАКОГО'],
            ])
            ->assertOk();

        $this->assertSame(
            [$bySku->id, $byCode->id, $byBarcode->id, $byExtraBarcode->id],
            array_column($response->json('products'), 'id'),
        );
        $this->assertSame('LE-13', $response->json('products.0.sku'));
        $this->assertSame([['identifier' => 'НЕТ-ТАКОГО', 'reason' => 'Товар не найден']], $response->json('unresolved'));
    }

    #[Test]
    public function пустой_список_отклоняется(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.certificates.resolve-products'), ['identifiers' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identifiers');
    }

    #[Test]
    public function без_права_на_сертификаты_недоступно(): void
    {
        Product::factory()->create(['sku' => 'LE-13']);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('admin.certificates.resolve-products'), ['identifiers' => ['LE-13']]);

        $this->assertFalse($response->isSuccessful());
    }
}
