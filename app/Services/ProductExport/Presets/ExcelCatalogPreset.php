<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Полный каталог в формате Excel (XLSX).
 *
 * Содержит два листа:
 * - «Товары» — все товары с атрибутами, ценами, остатками, изображениями
 * - «Категории» — дерево категорий с полным путём
 *
 * Использует chunk-подход для обработки больших каталогов.
 */
class ExcelCatalogPreset extends AbstractPreset
{
    public function key(): string { return 'excel'; }
    public function name(): string { return 'Полный каталог (Excel)'; }
    public function description(): string { return 'XLSX-файл с полным каталогом товаров на двух листах: товары с атрибутами и дерево категорий. Удобен для работы в Excel, Google Sheets, LibreOffice.'; }
    public function fileExtension(): string { return 'xlsx'; }
    public function mimeType(): string { return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; }
    public function color(): string { return 'green'; }
    public function icon(): string { return 'LuFileSpreadsheet'; }

    public function writeToStream($stream, ProductExport $export): void
    {
        $spreadsheet = new Spreadsheet();

        // ── Лист 1: Товары ──
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Товары');

        // Собираем заголовки: фиксированные + динамические атрибуты
        $fixedHeaders = [
            'ID', 'Артикул (SKU)', 'Код', 'Штрихкод', 'Название', 'Бренд',
            'Категория', 'Путь категории', 'Модель', 'Цена', 'Базовая цена',
            'Остаток', 'В наличии', 'Описание', 'Краткое описание',
            'Meta Title', 'Meta Description', 'Главное фото', 'Доп. фото',
            'Новинка', 'Бестселлер', 'URL',
        ];

        // Сначала собираем все уникальные имена атрибутов
        $allAttributeNames = $this->collectAttributeNames($export);

        $headers = array_merge($fixedHeaders, $allAttributeNames);

        // Записываем заголовки
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValue([$col, 1], $header);
            $col++;
        }

        // Стилизуем заголовки
        $lastCol = count($headers);
        $headerRange = 'A1:' . $this->columnLetter($lastCol) . '1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2B5797']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Замораживаем первую строку
        $sheet->freezePane('A2');

        // Записываем данные чанками
        $row = 2;
        $this->eachChunk($export, function ($items) use ($sheet, $allAttributeNames, &$row) {
            foreach ($items as $item) {
                $col = 1;

                // Фиксированные колонки
                $sheet->setCellValue([$col++, $row], $item['id']);
                $sheet->setCellValue([$col++, $row], $item['sku'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['code'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['barcode'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['name']);
                $sheet->setCellValue([$col++, $row], $item['brand_name'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['category_name'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['category_path'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['model_name'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['price']);
                $sheet->setCellValue([$col++, $row], $item['base_price']);
                $sheet->setCellValue([$col++, $row], $item['stock']);
                $sheet->setCellValue([$col++, $row], $item['stock'] > 0 ? 'Да' : 'Нет');
                $sheet->setCellValue([$col++, $row], strip_tags($item['description'] ?? ''));
                $sheet->setCellValue([$col++, $row], $item['short_description'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['meta_title'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['meta_description'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['main_image'] ?? '');
                $sheet->setCellValue([$col++, $row], implode("\n", $item['additional_images']));
                $sheet->setCellValue([$col++, $row], $item['is_new'] ? 'Да' : 'Нет');
                $sheet->setCellValue([$col++, $row], $item['is_bestseller'] ? 'Да' : 'Нет');
                $sheet->setCellValue([$col++, $row], $item['url'] ?? '');

                // Атрибуты (в соответствующие колонки)
                $attrMap = [];
                foreach ($item['attributes'] as $attr) {
                    $val = $attr['value'];
                    if ($attr['unit']) {
                        $val .= ' ' . $attr['unit'];
                    }
                    $attrMap[$attr['name']] = $val;
                }

                foreach ($allAttributeNames as $attrName) {
                    $sheet->setCellValue([$col++, $row], $attrMap[$attrName] ?? '');
                }

                $row++;
            }
        });

        // Автоширина для ключевых колонок (не все, чтобы не тормозить)
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'I', 'J', 'K', 'L', 'M'] as $colLetter) {
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // ── Лист 2: Категории ──
        $catSheet = $spreadsheet->createSheet();
        $catSheet->setTitle('Категории');

        $catHeaders = ['ID', 'Название', 'ID родителя', 'Полный путь'];
        $col = 1;
        foreach ($catHeaders as $h) {
            $catSheet->setCellValue([$col, 1], $h);
            $col++;
        }

        $catHeaderRange = 'A1:D1';
        $catSheet->getStyle($catHeaderRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2B5797']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $catSheet->freezePane('A2');

        $categories = $this->fetchCategories();
        $catRow = 2;
        foreach ($categories as $cat) {
            $ancestors = $cat->ancestors->pluck('name')->toArray();
            $ancestors[] = $cat->name;
            $fullPath = implode(' > ', $ancestors);

            $catSheet->setCellValue([1, $catRow], $cat->id);
            $catSheet->setCellValue([2, $catRow], $cat->name);
            $catSheet->setCellValue([3, $catRow], $cat->parent_id ?? '');
            $catSheet->setCellValue([4, $catRow], $fullPath);
            $catRow++;
        }

        foreach (['A', 'B', 'C', 'D'] as $colLetter) {
            $catSheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // Возвращаемся на первый лист
        $spreadsheet->setActiveSheetIndex(0);

        // Записываем в поток
        $writer = new Xlsx($spreadsheet);
        $writer->save($stream);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    /**
     * Собрать все уникальные имена атрибутов из каталога.
     */
    protected function collectAttributeNames(ProductExport $export): array
    {
        $names = [];

        $this->eachChunk($export, function ($items) use (&$names) {
            foreach ($items as $item) {
                foreach ($item['attributes'] as $attr) {
                    $names[$attr['name']] = true;
                }
            }
        });

        return array_keys($names);
    }

    /**
     * Преобразовать номер колонки в букву Excel (1 → A, 27 → AA).
     */
    protected function columnLetter(int $num): string
    {
        $letter = '';
        while ($num > 0) {
            $num--;
            $letter = chr(65 + ($num % 26)) . $letter;
            $num = intdiv($num, 26);
        }
        return $letter;
    }
}
