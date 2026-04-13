<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\Presets\PresetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тест стандартных выгрузок (пресетов) для CMS-движков.
 *
 * Проверяет:
 * - Регистрацию всех 8 пресетов в PresetRegistry
 * - Генерацию валидных файлов для каждого пресета (YML, Shopify, WooCommerce и т.д.)
 * - API контроллера (получение списка, генерация ссылки, удаление)
 * - Скачивание пресетной выгрузки по hash-ссылке
 * - Кэширование файлов на диске
 * - Защиту маршрутов авторизацией
 */
class ExportPresetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('super-admin');

        // Создаём тестовые данные: бренд, категория, товары с атрибутами
        $brand = Brand::factory()->create(['name' => 'TestBrand']);
        $category = Category::create([
            'name' => 'Тестовая категория',
            'slug' => 'test-category',
            'is_active' => true,
        ]);

        Product::factory()->count(3)->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'base_price' => 1500.00,
            'description' => '<p>Тестовое описание товара</p>',
            'short_description' => 'Краткое описание',
        ]);
    }

    // ═══════════════════════════════════════════════
    // Регистрация пресетов
    // ═══════════════════════════════════════════════

    public function test_all_eight_presets_are_registered(): void
    {
        $registry = app(PresetRegistry::class);
        $all = $registry->all();

        $this->assertCount(8, $all, 'Должно быть зарегистрировано 8 пресетов');

        $expectedKeys = ['yml', 'shopify', 'woocommerce', 'vk', 'google_merchant', 'tilda', 'opencart', 'cscart'];
        foreach ($expectedKeys as $key) {
            $this->assertNotNull($registry->resolve($key), "Пресет '{$key}' не найден в реестре");
        }
    }

    public function test_preset_registry_returns_correct_metadata(): void
    {
        $registry = app(PresetRegistry::class);
        $data = $registry->toArray();

        $this->assertCount(8, $data);

        foreach ($data as $preset) {
            $this->assertArrayHasKey('key', $preset);
            $this->assertArrayHasKey('name', $preset);
            $this->assertArrayHasKey('description', $preset);
            $this->assertArrayHasKey('extension', $preset);
            $this->assertArrayHasKey('color', $preset);
            $this->assertArrayHasKey('icon', $preset);
            $this->assertNotEmpty($preset['name'], "Пресет '{$preset['key']}' без названия");
            $this->assertNotEmpty($preset['description'], "Пресет '{$preset['key']}' без описания");
        }
    }

    // ═══════════════════════════════════════════════
    // Генерация файлов пресетов
    // ═══════════════════════════════════════════════

    /**
     * @dataProvider presetKeysProvider
     */
    public function test_preset_generates_valid_file(string $presetKey): void
    {
        $registry = app(PresetRegistry::class);
        $preset = $registry->resolve($presetKey);

        $export = ProductExport::create([
            'user_id' => $this->user->id,
            'client_user_id' => $this->user->id,
            'name' => "Test {$presetKey}",
            'format' => $preset->fileExtension() === 'xml' ? 'xml' : 'csv',
            'preset' => $presetKey,
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        $response = $preset->generate($export);

        // Проверяем HTTP статус
        $this->assertEquals(200, $response->getStatusCode());

        // Проверяем Content-Type
        $this->assertStringContainsString(
            $preset->mimeType(),
            $response->headers->get('Content-Type'),
            "Неверный Content-Type для пресета {$presetKey}"
        );

        // Проверяем Content-Disposition
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            $preset->fileExtension(),
            $response->headers->get('Content-Disposition'),
            "Неверное расширение файла для пресета {$presetKey}"
        );

        // Генерируем контент
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertNotEmpty($content, "Пресет '{$presetKey}' сгенерировал пустой файл");

        // Проверяем отсутствие PHP-ошибок
        $this->assertStringNotContainsString('Fatal error', $content);
        $this->assertStringNotContainsString('Warning:', $content);

        echo "\n  ✅ Пресет {$presetKey}: " . strlen($content) . " байт\n";
    }

    public static function presetKeysProvider(): array
    {
        return [
            'YML (Yandex.Market)' => ['yml'],
            'Shopify CSV' => ['shopify'],
            'WooCommerce CSV' => ['woocommerce'],
            'VK Market CSV' => ['vk'],
            'Google Merchant XML' => ['google_merchant'],
            'Tilda CSV' => ['tilda'],
            'OpenCart CSV' => ['opencart'],
            'CS-Cart CSV' => ['cscart'],
        ];
    }

    // ═══════════════════════════════════════════════
    // Валидация XML пресетов
    // ═══════════════════════════════════════════════

    public function test_yml_generates_valid_xml_structure(): void
    {
        $registry = app(PresetRegistry::class);
        $preset = $registry->resolve('yml');

        $export = $this->createPresetExport('yml');
        $content = $this->generatePresetContent($preset, $export);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertNotFalse($xml, 'YML: невалидный XML — ' . ($errors ? $errors[0]->message : 'unknown'));
        $this->assertEquals('yml_catalog', $xml->getName(), 'Корневой элемент должен быть yml_catalog');
        $this->assertNotNull($xml->shop, 'YML: отсутствует элемент <shop>');
        $this->assertNotNull($xml->shop->categories, 'YML: отсутствует элемент <categories>');
        $this->assertNotNull($xml->shop->offers, 'YML: отсутствует элемент <offers>');

        // Проверяем offer'ы
        $offers = $xml->shop->offers->offer;
        $this->assertGreaterThan(0, count($offers), 'YML: нет offer-ов');

        // Первый оффер должен содержать обязательные поля
        $firstOffer = $offers[0];
        $this->assertNotEmpty((string) $firstOffer->name, 'YML offer: отсутствует name');
        $this->assertNotEmpty((string) $firstOffer->price, 'YML offer: отсутствует price');
        $this->assertNotEmpty((string) $firstOffer->currencyId, 'YML offer: отсутствует currencyId');

        echo "\n✅ YML: " . count($offers) . " offer-ов, валидный XML\n";
    }

    public function test_google_merchant_generates_valid_rss_feed(): void
    {
        $registry = app(PresetRegistry::class);
        $preset = $registry->resolve('google_merchant');

        $export = $this->createPresetExport('google_merchant');
        $content = $this->generatePresetContent($preset, $export);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertNotFalse($xml, 'Google Merchant: невалидный XML');
        $this->assertEquals('rss', $xml->getName(), 'Корневой элемент должен быть rss');
        $this->assertNotNull($xml->channel, 'Google Merchant: отсутствует channel');

        $ns = $xml->channel->children('http://base.google.com/ns/1.0');

        echo "\n✅ Google Merchant: валидный RSS 2.0 feed\n";
    }

    // ═══════════════════════════════════════════════
    // Валидация CSV пресетов
    // ═══════════════════════════════════════════════

    public function test_shopify_csv_has_required_columns(): void
    {
        $registry = app(PresetRegistry::class);
        $preset = $registry->resolve('shopify');

        $export = $this->createPresetExport('shopify');
        $content = $this->generatePresetContent($preset, $export);
        $content = ltrim($content, "\xEF\xBB\xBF"); // remove BOM

        $lines = explode("\n", trim($content));
        $this->assertGreaterThan(1, count($lines), 'Shopify CSV: нет данных');

        $header = str_getcsv($lines[0]);

        // Shopify обязательные колонки
        $requiredColumns = ['Handle', 'Title', 'Body (HTML)', 'Vendor', 'Variant SKU', 'Variant Price', 'Image Src'];
        foreach ($requiredColumns as $col) {
            $this->assertContains($col, $header, "Shopify CSV: отсутствует обязательная колонка '{$col}'");
        }

        echo "\n✅ Shopify CSV: " . count($header) . " колонок, " . (count($lines) - 1) . " строк данных\n";
    }

    public function test_woocommerce_csv_has_required_columns(): void
    {
        $registry = app(PresetRegistry::class);
        $preset = $registry->resolve('woocommerce');

        $export = $this->createPresetExport('woocommerce');
        $content = $this->generatePresetContent($preset, $export);
        $content = ltrim($content, "\xEF\xBB\xBF");

        $lines = explode("\n", trim($content));
        $header = str_getcsv($lines[0]);

        $requiredColumns = ['SKU', 'Name', 'Description', 'Regular price', 'Sale price', 'Stock', 'Categories', 'Images'];
        foreach ($requiredColumns as $col) {
            $this->assertContains($col, $header, "WooCommerce CSV: отсутствует колонка '{$col}'");
        }

        echo "\n✅ WooCommerce CSV: " . count($header) . " колонок\n";
    }

    public function test_cscart_csv_uses_semicolon_separator(): void
    {
        $registry = app(PresetRegistry::class);
        $preset = $registry->resolve('cscart');

        $export = $this->createPresetExport('cscart');
        $content = $this->generatePresetContent($preset, $export);
        $content = ltrim($content, "\xEF\xBB\xBF");

        $lines = explode("\n", trim($content));
        $header = str_getcsv($lines[0], ';');

        $this->assertContains('Product code', $header, 'CS-Cart: отсутствует Product code');
        $this->assertContains('Product name', $header, 'CS-Cart: отсутствует Product name');
        $this->assertContains('Price', $header, 'CS-Cart: отсутствует Price');

        echo "\n✅ CS-Cart CSV: " . count($header) . " колонок, разделитель ';'\n";
    }

    // ═══════════════════════════════════════════════
    // API Controller — ExportPresetController
    // ═══════════════════════════════════════════════

    public function test_guest_cannot_access_presets_api(): void
    {
        $this->getJson('/cabinet/export-presets')
            ->assertStatus(401); // unauthorized (JSON)
    }

    public function test_authenticated_user_can_list_presets(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/cabinet/export-presets');

        $response->assertOk();
        $response->assertJsonStructure([
            'presets' => [
                '*' => ['key', 'name', 'description', 'extension', 'color', 'icon', 'generated', 'download_url'],
            ],
        ]);

        $presets = $response->json('presets');
        $this->assertCount(8, $presets, 'API должен возвращать 8 пресетов');

        // Изначально ни один не должен быть сгенерирован
        foreach ($presets as $preset) {
            $this->assertFalse($preset['generated'], "Пресет '{$preset['key']}' не должен быть сгенерирован изначально");
        }

        echo "\n✅ API index: 8 пресетов, ни один не сгенерирован\n";
    }

    public function test_user_can_generate_preset_link(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/cabinet/export-presets/yml/generate');

        $response->assertOk();
        $response->assertJsonStructure(['download_url', 'hash', 'export_id']);

        // Проверяем, что запись создана
        $export = ProductExport::where('user_id', $this->user->id)
            ->where('preset', 'yml')
            ->first();

        $this->assertNotNull($export, 'Запись ProductExport не создана');
        $this->assertEquals('yml', $export->preset);
        $this->assertTrue($export->is_active);
        $this->assertEquals($this->user->id, $export->client_user_id);

        echo "\n✅ API generate: YML ссылка создана ({$export->hash})\n";
    }

    public function test_generate_returns_existing_link_on_second_call(): void
    {
        // Первый вызов
        $response1 = $this->actingAs($this->user)
            ->postJson('/cabinet/export-presets/shopify/generate');

        $hash1 = $response1->json('hash');

        // Второй вызов — должен вернуть тот же hash
        $response2 = $this->actingAs($this->user)
            ->postJson('/cabinet/export-presets/shopify/generate');

        $hash2 = $response2->json('hash');

        $this->assertEquals($hash1, $hash2, 'Повторный вызов должен вернуть тот же hash');

        // В БД должна быть ровно 1 запись
        $count = ProductExport::where('user_id', $this->user->id)
            ->where('preset', 'shopify')
            ->count();

        $this->assertEquals(1, $count, 'Должна быть 1 запись, а не 2');

        echo "\n✅ Идемпотентность: повторный вызов возвращает тот же hash\n";
    }

    public function test_generate_returns_404_for_unknown_preset(): void
    {
        $this->actingAs($this->user)
            ->postJson('/cabinet/export-presets/nonexistent/generate')
            ->assertStatus(404);
    }

    public function test_user_can_delete_preset(): void
    {
        // Сначала генерируем
        $this->actingAs($this->user)
            ->postJson('/cabinet/export-presets/tilda/generate');

        $this->assertDatabaseHas('product_exports', [
            'user_id' => $this->user->id,
            'preset' => 'tilda',
        ]);

        // Удаляем
        $this->actingAs($this->user)
            ->deleteJson('/cabinet/export-presets/tilda')
            ->assertOk();

        $this->assertDatabaseMissing('product_exports', [
            'user_id' => $this->user->id,
            'preset' => 'tilda',
        ]);

        echo "\n✅ Удаление пресета работает\n";
    }

    // ═══════════════════════════════════════════════
    // Скачивание по hash-ссылке
    // ═══════════════════════════════════════════════

    public function test_preset_export_is_downloadable_via_hash(): void
    {
        // Генерируем пресет
        $response = $this->actingAs($this->user)
            ->postJson('/cabinet/export-presets/yml/generate');

        $hash = $response->json('hash');

        // Скачиваем (публичный маршрут, без авторизации)
        $downloadResponse = $this->get("/export/{$hash}");
        $downloadResponse->assertStatus(200);
        $downloadResponse->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        echo "\n✅ Скачивание YML по hash работает\n";
    }

    public function test_inactive_preset_returns_404(): void
    {
        $export = ProductExport::create([
            'user_id' => $this->user->id,
            'client_user_id' => $this->user->id,
            'name' => 'Inactive YML',
            'format' => 'xml',
            'preset' => 'yml',
            'filters' => [],
            'fields' => [],
            'is_active' => false,
        ]);

        $this->get("/export/{$export->hash}")
            ->assertStatus(404);
    }

    // ═══════════════════════════════════════════════
    // Кэширование
    // ═══════════════════════════════════════════════

    public function test_cache_file_is_created_after_download(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/cabinet/export-presets/opencart/generate');

        $hash = $response->json('hash');

        // Скачиваем — должен создаться кэш
        $this->get("/export/{$hash}")->assertOk();

        $export = ProductExport::where('hash', $hash)->first();
        $this->assertNotNull($export->cached_at, 'cached_at должен быть заполнен');
        $this->assertFileExists($export->getCacheFilePath(), 'Файл кэша должен существовать');

        // Чистим за собой
        @unlink($export->getCacheFilePath());

        echo "\n✅ Кэш-файл создаётся при первом скачивании\n";
    }

    public function test_product_export_model_has_preset_methods(): void
    {
        $presetExport = ProductExport::create([
            'user_id' => $this->user->id,
            'client_user_id' => $this->user->id,
            'name' => 'YML Test',
            'format' => 'xml',
            'preset' => 'yml',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        $customExport = ProductExport::create([
            'user_id' => $this->user->id,
            'client_user_id' => $this->user->id,
            'name' => 'Custom Test',
            'format' => 'csv',
            'preset' => null,
            'filters' => [],
            'fields' => [['key' => 'name']],
            'is_active' => true,
        ]);

        $this->assertTrue($presetExport->isPreset(), 'Пресетная выгрузка: isPreset() = true');
        $this->assertFalse($customExport->isPreset(), 'Кастомная выгрузка: isPreset() = false');
        $this->assertFalse($presetExport->hasFreshCache(), 'Без cached_at не может быть свежего кэша');
        $this->assertStringContainsString('exports', $presetExport->getCacheFilePath());
    }

    // ═══════════════════════════════════════════════
    // Изоляция данных между пользователями
    // ═══════════════════════════════════════════════

    public function test_users_have_separate_preset_links(): void
    {
        $user2 = User::factory()->create();
        $user2->assignRole('super-admin');

        // User 1 генерирует YML
        $r1 = $this->actingAs($this->user)
            ->postJson('/cabinet/export-presets/yml/generate');

        // User 2 генерирует YML
        $r2 = $this->actingAs($user2)
            ->postJson('/cabinet/export-presets/yml/generate');

        $this->assertNotEquals(
            $r1->json('hash'),
            $r2->json('hash'),
            'Разные пользователи должны получить разные hash-ссылки'
        );

        echo "\n✅ Изоляция: пользователи получают разные ссылки\n";
    }

    // ═══════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════

    protected function createPresetExport(string $presetKey): ProductExport
    {
        $registry = app(PresetRegistry::class);
        $preset = $registry->resolve($presetKey);

        return ProductExport::create([
            'user_id' => $this->user->id,
            'client_user_id' => $this->user->id,
            'name' => "Test {$presetKey}",
            'format' => $preset->fileExtension() === 'xml' ? 'xml' : 'csv',
            'preset' => $presetKey,
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);
    }

    protected function generatePresetContent($preset, ProductExport $export): string
    {
        $response = $preset->generate($export);
        ob_start();
        $response->sendContent();
        return ob_get_clean();
    }
}
