<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Проверяет, что разделитель multi-value полей в конструкторе выгрузки
 * корректно доходит до сгенерированного значения, в т.ч. через HTTP-роут
 * /preview, где Laravel-middleware TrimStrings ранее обрезал `\n`, пробелы
 * и ломал «| / ; ,»-разделители.
 */
class ProductExportSeparatorModifierTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('super-admin');
    }

    protected function makeProductWithBarcodes(array $barcodes): Product
    {
        $product = Product::factory()->create();
        foreach ($barcodes as $bc) {
            $product->barcodes()->create(['barcode' => $bc]);
        }

        return $product;
    }

    /**
     * Все коды разделителей с фронта корректно мапятся в реальные символы.
     */
    public function test_separator_codes_are_mapped_correctly(): void
    {
        $product = $this->makeProductWithBarcodes(['111', '222', '333']);
        $service = app(ProductExportService::class);

        $cases = [
            'comma' => '111, 222, 333',
            'semicolon' => '111; 222; 333',
            'pipe' => '111 | 222 | 333',
            'newline' => "111\n222\n333",
            'slash' => '111 / 222 / 333',
        ];

        foreach ($cases as $code => $expected) {
            $export = (new ProductExport)->forceFill([
                'fields' => [
                    ['key' => 'barcodes.barcode', 'modifiers' => ['separator' => $code]],
                ],
                'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
                'client_user_id' => null,
            ]);

            $row = $service->fetchData($export, 1)->first();

            $this->assertSame(
                $expected,
                $row['barcodes.barcode'],
                "Separator code `{$code}` не дал ожидаемый разделитель"
            );
        }
    }

    /**
     * Legacy-формат: уже сохранённые в БД выгрузки шлют сырой символ ' / '
     * вместо кода — должен и дальше работать как до фикса.
     */
    public function test_legacy_raw_separator_still_works(): void
    {
        $product = $this->makeProductWithBarcodes(['111', '222']);
        $service = app(ProductExportService::class);

        $export = (new ProductExport)->forceFill([
            'fields' => [
                ['key' => 'barcodes.barcode', 'modifiers' => ['separator' => ' / ']],
            ],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);

        $row = $service->fetchData($export, 1)->first();

        $this->assertSame('111 / 222', $row['barcodes.barcode']);
    }

    /**
     * Пустая строка (что произошло бы, если новая строка попала под TrimStrings)
     * не должна оставлять штрихкоды слипшимися — fallback на запятую.
     */
    public function test_empty_separator_falls_back_to_comma(): void
    {
        $product = $this->makeProductWithBarcodes(['111', '222']);
        $service = app(ProductExportService::class);

        $export = (new ProductExport)->forceFill([
            'fields' => [
                ['key' => 'barcodes.barcode', 'modifiers' => ['separator' => '']],
            ],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);

        $row = $service->fetchData($export, 1)->first();

        $this->assertSame('111, 222', $row['barcodes.barcode']);
    }

    /**
     * Самый главный тест: код `newline` доходит до бэка через HTTP — то самое
     * место, где раньше Laravel-middleware TrimStrings обрезал «\n» в пустую строку.
     */
    public function test_newline_separator_survives_trim_middleware_via_preview(): void
    {
        $product = $this->makeProductWithBarcodes(['111', '222']);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.product-exports.preview'), [
                'filters' => [
                    ['field' => 'id', 'operator' => '=', 'value' => $product->id],
                ],
                'fields' => [
                    ['key' => 'barcodes.barcode', 'label' => 'Штрихкоды', 'modifiers' => ['separator' => 'newline']],
                ],
                'client_user_id' => null,
            ]);

        $response->assertOk();

        $row = $response->json('data.0');
        $this->assertSame("111\n222", $row['barcodes.barcode']);
    }
}
