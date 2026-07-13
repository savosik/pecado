<?php

namespace Tests\Feature\Cart;

use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Services\Cart\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class ImportOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $priceService = $this->createMock(PriceServiceInterface::class);
        $priceService->method('getUserPrice')->willReturn(100.0);
        $priceService->method('getBasePrice')->willReturn(120.0);
        $priceService->method('getPriceResult')->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
        $priceService->method('convertPrice')->willReturnArgument(0);
        $this->app->instance(PriceServiceInterface::class, $priceService);

        $stockService = $this->createMock(StockServiceInterface::class);
        $stockService->method('getStock')->willReturn(['available' => 100, 'preorder' => 50]);
        $stockService->method('getAvailableStock')->willReturn(100);
        $stockService->method('getPreorderStock')->willReturn(50);
        $this->app->instance(StockServiceInterface::class, $stockService);
    }

    public function test_import_resolves_by_sku_code_and_barcode(): void
    {
        $bySku = Product::factory()->create(['sku' => 'ART-SKU-1']);
        $byCode = Product::factory()->create(['code' => 'CODE-777']);
        $byBarcode = Product::factory()->create(['barcode' => '4600000000001']);
        $byExtraBarcode = Product::factory()->create();
        ProductBarcode::create(['product_id' => $byExtraBarcode->id, 'barcode' => '4600000000002']);

        $response = $this->actingAs($this->user)->postJson('/api/cart/import-order', [
            'items' => [
                ['identifier' => 'ART-SKU-1', 'quantity' => '2'],
                ['identifier' => 'CODE-777', 'quantity' => '3'],
                ['identifier' => '4600000000001', 'quantity' => '1'],
                ['identifier' => '4600000000002', 'quantity' => '5'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('added_count', 4)
            ->assertJsonPath('unresolved', []);

        $cart = $this->user->carts()->where('is_active', true)->first();
        $this->assertSame(2, (int) $cart->items()->where('product_id', $bySku->id)->sum('quantity'));
        $this->assertSame(3, (int) $cart->items()->where('product_id', $byCode->id)->sum('quantity'));
        $this->assertSame(1, (int) $cart->items()->where('product_id', $byBarcode->id)->sum('quantity'));
        $this->assertSame(5, (int) $cart->items()->where('product_id', $byExtraBarcode->id)->sum('quantity'));
    }

    public function test_import_is_additive_to_existing_quantity(): void
    {
        $product = Product::factory()->create(['sku' => 'ADD-1']);
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'item_type' => 'instock',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/cart/import-order', [
            'items' => [
                ['identifier' => 'ADD-1', 'quantity' => '3'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('added_count', 1);
        $this->assertSame(5, (int) $cart->items()->where('product_id', $product->id)->sum('quantity'));
    }

    public function test_unresolved_and_invalid_quantity_are_reported(): void
    {
        $product = Product::factory()->create(['sku' => 'OK-1']);

        $response = $this->actingAs($this->user)->postJson('/api/cart/import-order', [
            'items' => [
                ['identifier' => 'OK-1', 'quantity' => '2'],
                ['identifier' => 'DOES-NOT-EXIST', 'quantity' => '1'],
                ['identifier' => 'OK-1', 'quantity' => '0'],
                ['identifier' => 'OK-1', 'quantity' => 'abc'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('added_count', 1);

        $unresolved = collect($response->json('unresolved'));
        $this->assertCount(3, $unresolved);
        $this->assertTrue($unresolved->contains(fn ($u) => $u['identifier'] === 'DOES-NOT-EXIST' && $u['reason'] === 'Товар не найден'));
        $this->assertSame(2, $unresolved->where('reason', 'Неверное количество')->count());

        $cart = $this->user->carts()->where('is_active', true)->first();
        $this->assertSame(2, (int) $cart->items()->where('product_id', $product->id)->sum('quantity'));
    }

    public function test_duplicate_identifiers_are_summed(): void
    {
        $product = Product::factory()->create(['sku' => 'DUP-1']);

        $response = $this->actingAs($this->user)->postJson('/api/cart/import-order', [
            'items' => [
                ['identifier' => 'DUP-1', 'quantity' => '2'],
                ['identifier' => 'DUP-1', 'quantity' => '4'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('added_count', 1);
        $cart = $this->user->carts()->where('is_active', true)->first();
        $this->assertSame(6, (int) $cart->items()->where('product_id', $product->id)->sum('quantity'));
    }

    public function test_import_nothing_resolved_returns_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/cart/import-order', [
            'items' => [
                ['identifier' => 'UNKNOWN', 'quantity' => '1'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('added_count', 0)
            ->assertJsonPath('status', 'warning');
    }

    public function test_import_file_csv(): void
    {
        Product::factory()->create(['sku' => 'CSV-1']);
        Product::factory()->create(['code' => 'CSV-2']);

        $content = "\xEF\xBB\xBFИдентификатор;Количество\nCSV-1;2\nCSV-2;3\n";
        $file = UploadedFile::fake()->createWithContent('order.csv', $content);

        $response = $this->actingAs($this->user)->post('/api/cart/import-order-file', [
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('added_count', 2);

        $cart = $this->user->carts()->where('is_active', true)->first();
        $this->assertSame(5, (int) $cart->items()->sum('quantity'));
    }

    public function test_parse_file_reads_xlsx_with_header(): void
    {
        Product::factory()->create(['sku' => 'XLS-1']);
        Product::factory()->create(['sku' => 'XLS-2']);

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Идентификатор', 'Количество'],
            ['XLS-1', 4],
            ['XLS-2', 1],
        ], null, 'A1');
        (new XlsxWriter($spreadsheet))->save($path);

        $upload = new UploadedFile($path, 'order.xlsx', null, null, true);

        $service = app(OrderImportService::class);
        $rows = $service->parseFile($upload);

        @unlink($path);

        $this->assertCount(2, $rows);
        $this->assertSame('XLS-1', $rows[0]['identifier']);
        $this->assertSame('4', (string) $rows[0]['quantity']);
        $this->assertSame('XLS-2', $rows[1]['identifier']);
    }

    public function test_template_download_returns_xlsx(): void
    {
        $response = $this->actingAs($this->user)->get('/api/cart/import-order/template');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );
    }
}
