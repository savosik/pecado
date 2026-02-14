<?php

namespace Tests\Feature;

use App\Enums\ExportFormat;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExportService;
use Tests\TestCase;

/**
 * Тест генерации файлов выгрузки для всех форматов (JSON, CSV, XML, XLS)
 * со всеми доступными полями экспорта.
 *
 * Проверяет, что каждый формат генерируется без ошибок,
 * возвращает корректный Content-Type и валидный контент.
 */
class ProductExportAllFormatsTest extends TestCase
{
    /**
     * Все доступные поля для экспорта (статические + динамические атрибуты).
     */
    protected function getAllFields(): array
    {
        return [
            // ─── Основные ───
            ['key' => 'id', 'label' => 'ID товара'],
            ['key' => 'name', 'label' => 'Наименование'],
            ['key' => 'sku', 'label' => 'Артикул'],
            ['key' => 'code', 'label' => 'Код товара'],
            ['key' => 'barcode', 'label' => 'Штрихкод (основной)'],
            ['key' => 'base_price', 'label' => 'Базовая цена'],
            ['key' => 'recommended_price', 'label' => 'Рекомендованная цена'],
            ['key' => 'slug', 'label' => 'URL-slug'],
            ['key' => 'url', 'label' => 'URL'],
            ['key' => 'tnved', 'label' => 'Код ТН ВЭД'],
            ['key' => 'external_id', 'label' => 'Внешний ID'],
            ['key' => 'description', 'label' => 'Описание'],
            ['key' => 'short_description', 'label' => 'Краткое описание'],
            ['key' => 'meta_title', 'label' => 'Meta Title'],
            ['key' => 'meta_description', 'label' => 'Meta Description'],
            ['key' => 'description_plain', 'label' => 'Описание (без тегов)'],
            ['key' => 'short_description_plain', 'label' => 'Краткое описание (без тегов)'],

            // ─── Флаги ───
            ['key' => 'is_new', 'label' => 'Новинка', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],
            ['key' => 'is_bestseller', 'label' => 'Бестселлер', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],
            ['key' => 'is_marked', 'label' => 'Маркировка', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],
            ['key' => 'is_liquidation', 'label' => 'Ликвидация', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],
            ['key' => 'for_marketplaces', 'label' => 'Для маркетплейсов', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],

            // ─── Бренд ───
            ['key' => 'brand.name', 'label' => 'Название бренда'],
            ['key' => 'brand.slug', 'label' => 'Slug бренда'],
            ['key' => 'brand.category', 'label' => 'Категория бренда'],

            // ─── Модель ───
            ['key' => 'model.name', 'label' => 'Модель (название)'],
            ['key' => 'model.code', 'label' => 'Модель (код)'],

            // ─── Категория ───
            ['key' => 'category.name', 'label' => 'Категория'],
            ['key' => 'category_path', 'label' => 'Путь категории'],

            // ─── Сертификаты ───
            ['key' => 'certificates.name', 'label' => 'Сертификаты', 'modifiers' => ['separator' => ', ']],

            // ─── Размерная сетка ───
            ['key' => 'size_chart.name', 'label' => 'Размерная сетка'],

            // ─── Складские остатки ───
            ['key' => 'warehouses.name', 'label' => 'Склады', 'modifiers' => ['separator' => ', ']],
            ['key' => 'warehouses.pivot.quantity', 'label' => 'Остатки по складам', 'modifiers' => ['separator' => ', ']],
            ['key' => 'total_stock', 'label' => 'Суммарный остаток'],

            // ─── Штрихкоды ───
            ['key' => 'barcodes.barcode', 'label' => 'Все штрихкоды', 'modifiers' => ['separator' => ', ']],

            // ─── Медиа ───
            ['key' => 'main_image', 'label' => 'Главное изображение'],
            ['key' => 'additional_images', 'label' => 'Доп. изображения', 'modifiers' => ['separator' => '; ']],
            ['key' => 'all_images', 'label' => 'Все изображения', 'modifiers' => ['separator' => '; ']],
            ['key' => 'video', 'label' => 'Видео'],

            // ─── Пользовательские (по клиенту) ───
            ['key' => 'discounted_price', 'label' => 'Цена со скидкой'],
            ['key' => 'discount_percentage', 'label' => 'Процент скидки'],
            ['key' => 'user_stock_available', 'label' => 'Остаток (основной)'],
            ['key' => 'user_stock_preorder', 'label' => 'Остаток (предзаказ)'],
            ['key' => 'client_region', 'label' => 'Регион клиента'],

            // ─── Служебные ───
            ['key' => 'created_at', 'label' => 'Дата создания'],
            ['key' => 'updated_at', 'label' => 'Дата обновления'],

            // ─── Динамические атрибуты (все из БД) ───
            ['key' => 'attribute.1', 'label' => 'Тип упаковки'],
            ['key' => 'attribute.2', 'label' => 'Вес брутто, кг'],
            ['key' => 'attribute.3', 'label' => 'Вес нетто, кг'],
            ['key' => 'attribute.4', 'label' => 'Длина упаковки, м'],
            ['key' => 'attribute.5', 'label' => 'Ширина упаковки, м'],
            ['key' => 'attribute.6', 'label' => 'Высота упаковки, м'],
            ['key' => 'attribute.7', 'label' => 'Коробок в упаковке'],
            ['key' => 'attribute.8', 'label' => 'Модель (атрибут)'],
            ['key' => 'attribute.9', 'label' => 'Со швом сзади'],
            ['key' => 'attribute.10', 'label' => 'С уплотненным носком'],
            ['key' => 'attribute.11', 'label' => 'Состав'],
            ['key' => 'attribute.12', 'label' => 'С кружевной резинкой'],
            ['key' => 'attribute.13', 'label' => 'Рекомендации по применению'],
            ['key' => 'attribute.14', 'label' => 'Основной цвет'],
            ['key' => 'attribute.15', 'label' => 'Ткань'],
            ['key' => 'attribute.16', 'label' => 'Размер'],
            ['key' => 'attribute.17', 'label' => 'Маркированный товар'],
            ['key' => 'attribute.18', 'label' => 'Страна происхождения'],
            ['key' => 'attribute.19', 'label' => 'Состав изделия'],
            ['key' => 'attribute.20', 'label' => 'Классификация для отчетности'],
            ['key' => 'attribute.21', 'label' => 'С доступом'],
            ['key' => 'attribute.22', 'label' => 'С открытыми ягодицами'],
            ['key' => 'attribute.23', 'label' => 'Кол-во изделий в розн. упаковке'],
            ['key' => 'attribute.24', 'label' => 'Composition'],
            ['key' => 'attribute.26', 'label' => 'Количество в комплекте'],
            ['key' => 'attribute.27', 'label' => 'С завышенной талией'],
            ['key' => 'attribute.28', 'label' => 'С открытой грудью'],
            ['key' => 'attribute.30', 'label' => 'С украшениями'],
            ['key' => 'attribute.31', 'label' => 'Вид трусов'],
            ['key' => 'attribute.33', 'label' => 'Дополнительный цвет'],
            ['key' => 'attribute.34', 'label' => 'Подходит для маркетплейсов'],
            ['key' => 'attribute.35', 'label' => 'Декор'],
            ['key' => 'attribute.36', 'label' => 'Вид ткани'],
            ['key' => 'attribute.37', 'label' => 'Дата производства'],
            ['key' => 'attribute.38', 'label' => 'Доп. вид ткани'],
            ['key' => 'attribute.40', 'label' => 'Ссылка на видео'],
            ['key' => 'attribute.42', 'label' => 'Основной материал'],
        ];
    }

    /**
     * Создать профиль выгрузки с указанным форматом и всеми полями.
     */
    protected function createExport(ExportFormat $format, string $name): ProductExport
    {
        $user = User::where('is_admin', true)->first();

        return ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => $name,
            'format' => $format,
            'fields' => $this->getAllFields(),
            'filters' => ['logic' => 'and', 'conditions' => []],
            'is_active' => true,
        ]);
    }

    /**
     * Генерация контента через сервис (ob_start / ob_get_clean).
     */
    protected function generateContent(ProductExport $export): string
    {
        $service = app(ProductExportService::class);
        $response = $service->generate($export);

        ob_start();
        $response->sendContent();
        return ob_get_clean();
    }

    // ─── JSON ──────────────────────────────────────

    public function test_json_export_generates_valid_file(): void
    {
        $export = $this->createExport(ExportFormat::JSON, 'Test All Fields JSON');
        $service = app(ProductExportService::class);

        $response = $service->generate($export);

        // Проверяем HTTP статус
        $this->assertEquals(200, $response->getStatusCode());

        // Проверяем Content-Type
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));

        // Проверяем Content-Disposition
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.json', $response->headers->get('Content-Disposition'));

        // Получаем контент
        $content = $this->generateContent($export);

        // Контент не пустой
        $this->assertNotEmpty($content);

        // JSON-парсинг без ошибок
        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded, 'JSON decode failed: ' . json_last_error_msg());
        $this->assertIsArray($decoded);

        // Должен содержать записи (146 товаров в БД)
        $this->assertNotEmpty($decoded, 'JSON export returned empty array');

        // Каждая запись должна содержать ключи из экспорта
        $firstRow = $decoded[0];
        $this->assertArrayHasKey('id', $firstRow);
        $this->assertArrayHasKey('name', $firstRow);
        $this->assertArrayHasKey('sku', $firstRow);
        $this->assertArrayHasKey('brand.name', $firstRow);

        echo "\n✅ JSON: " . count($decoded) . " строк, " . strlen($content) . " байт\n";
    }

    // ─── CSV ───────────────────────────────────────

    public function test_csv_export_generates_valid_file(): void
    {
        $export = $this->createExport(ExportFormat::CSV, 'Test All Fields CSV');
        $service = app(ProductExportService::class);

        $response = $service->generate($export);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.csv', $response->headers->get('Content-Disposition'));

        $content = $this->generateContent($export);
        $this->assertNotEmpty($content);

        // Убираем BOM
        $content = ltrim($content, "\xEF\xBB\xBF");

        // Парсим CSV
        $lines = explode("\n", trim($content));
        $this->assertGreaterThan(1, count($lines), 'CSV должен иметь заголовок + данные');

        // Заголовок
        $header = str_getcsv($lines[0], ';');
        $this->assertNotEmpty($header);
        $this->assertContains('ID товара', $header);
        $this->assertContains('Наименование', $header);

        // Данные
        $dataLine = str_getcsv($lines[1], ';');
        $this->assertCount(count($header), $dataLine, 'Количество колонок в строке данных не совпадает с заголовком');

        // Проверяем что нет PHP-ошибок в контенте
        $this->assertStringNotContainsString('Fatal error', $content);
        $this->assertStringNotContainsString('could not be converted to string', $content);

        echo "\n✅ CSV: " . (count($lines) - 1) . " строк данных, " . count($header) . " колонок, " . strlen($content) . " байт\n";
    }

    // ─── XML ───────────────────────────────────────

    public function test_xml_export_generates_valid_file(): void
    {
        $export = $this->createExport(ExportFormat::XML, 'Test All Fields XML');
        $service = app(ProductExportService::class);

        $response = $service->generate($export);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.xml', $response->headers->get('Content-Disposition'));

        $content = $this->generateContent($export);
        $this->assertNotEmpty($content);

        // XML-парсинг без ошибок
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertNotFalse($xml, 'XML parse failed: ' . ($errors ? $errors[0]->message : 'unknown error'));

        // Корневой элемент — <products>
        $this->assertEquals('products', $xml->getName());

        // Должны быть дочерние <product>
        $products = $xml->product;
        $this->assertGreaterThan(0, count($products), 'XML export не содержит <product> элементов');

        // Первый продукт должен иметь экспортные поля
        $firstProduct = $products[0];
        $this->assertTrue(isset($firstProduct->id), 'XML: отсутствует поле <id>');
        $this->assertTrue(isset($firstProduct->name), 'XML: отсутствует поле <name>');

        // Проверяем что нет ошибок в XML
        $this->assertStringNotContainsString('Fatal error', $content);

        echo "\n✅ XML: " . count($products) . " продуктов, " . strlen($content) . " байт\n";
    }

    // ─── XLS (Excel) ──────────────────────────────

    public function test_xls_export_generates_valid_file(): void
    {
        $export = $this->createExport(ExportFormat::XLS, 'Test All Fields XLS');
        $service = app(ProductExportService::class);

        $response = $service->generate($export);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));

        $content = $this->generateContent($export);
        $this->assertNotEmpty($content);

        // XLSX — это ZIP-архив, проверяем валидность
        $tmpFile = tempnam(sys_get_temp_dir(), 'export_test_');
        file_put_contents($tmpFile, $content);

        $zip = new \ZipArchive;
        $opened = $zip->open($tmpFile);
        $this->assertTrue($opened === true, 'XLSX файл не является валидным ZIP-архивом');

        // Должен содержать [Content_Types].xml (стандарт OOXML)
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'), 'XLSX: отсутствует [Content_Types].xml');

        // Должен содержать лист данных
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'), 'XLSX: отсутствует sheet1.xml');

        // Читаем через PhpSpreadsheet для глубокой проверки
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx;
        $spreadsheet = $reader->load($tmpFile);
        $sheet = $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        // Заголовок в первой строке
        $this->assertGreaterThan(1, $highestRow, 'XLSX: должны быть данные помимо заголовка');

        // Проверяем первую ячейку заголовка
        $headerValue = $sheet->getCell('A1')->getValue();
        $this->assertNotEmpty($headerValue, 'XLSX: первая ячейка заголовка пуста');

        $zip->close();
        unlink($tmpFile);

        echo "\n✅ XLSX: {$highestRow} строк, колонки A-{$highestColumn}, " . strlen($content) . " байт\n";
    }

    // ─── HTTP Download ─────────────────────────────

    public function test_download_endpoint_works_for_all_formats(): void
    {
        $formats = [
            ExportFormat::JSON => 'application/json',
            ExportFormat::CSV => 'text/csv',
            ExportFormat::XML => 'application/xml',
            ExportFormat::XLS => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        foreach ($formats as $format => $expectedContentType) {
            $export = $this->createExport($format, "HTTP Test {$format->value}");

            $response = $this->get("/export/{$export->hash}");

            $response->assertStatus(200);
            $this->assertStringContainsString(
                $expectedContentType,
                $response->headers->get('Content-Type'),
                "Неверный Content-Type для формата {$format->value}"
            );
            $this->assertStringContainsString(
                'attachment',
                $response->headers->get('Content-Disposition'),
                "Отсутствует Content-Disposition для формата {$format->value}"
            );

            echo "  ✅ HTTP {$format->value}: OK\n";
        }
    }

    // ─── Inactive Export ────────────────────────────

    public function test_inactive_export_returns_404(): void
    {
        $export = $this->createExport(ExportFormat::JSON, 'Inactive Export Test');
        $export->update(['is_active' => false]);

        $response = $this->get("/export/{$export->hash}");
        $response->assertStatus(404);
    }
}
