<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Полный каталог в формате Excel (XLSX).
 *
 * Содержит два листа:
 * - «Товары» — все товары с атрибутами, ценами, остатками, изображениями
 * - «Категории» — дерево категорий с полным путём
 *
 * Оптимизация памяти:
 * - Один проход по данным (атрибуты собираются на лету, колонки дописываются)
 * - Нет autoSize (дорого по памяти — сканирует все ячейки)
 * - setPreCalculateFormulas(false) — нет формул, не нужен пересчёт
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
        $spreadsheet->getProperties()->setTitle('Каталог товаров');

        // ── Лист 1: Товары ──
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Товары');

        $fixedHeaders = [
            'ID', 'Артикул (SKU)', 'Код', 'Штрихкод', 'Название', 'Бренд',
            'Категория', 'Путь категории', 'Модель', 'Цена', 'Базовая цена',
            'Остаток', 'В наличии', 'Описание', 'Краткое описание',
            'Meta Title', 'Meta Description', 'Главное фото', 'Доп. фото',
            'Новинка', 'Бестселлер', 'URL',
        ];
        $fixedCount = count($fixedHeaders);

        // Записываем фиксированные заголовки
        foreach ($fixedHeaders as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }

        // Установим ширину ключевых колонок вручную (вместо autoSize)
        $columnWidths = [
            'A' => 8,  'B' => 15, 'C' => 12, 'D' => 15, 'E' => 40, 'F' => 18,
            'G' => 20, 'H' => 30, 'I' => 15, 'J' => 10, 'K' => 12, 'L' => 10,
        ];
        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // Замораживаем первую строку
        $sheet->freezePane('A2');

        // Один проход: данные + сбор уникальных атрибутов
        $attrNameIndex = []; // name → column_offset
        $row = 2;

        $this->eachChunk($export, function ($items) use ($sheet, $fixedCount, &$attrNameIndex, &$row) {
            foreach ($items as $item) {
                $col = 1;

                // Фиксированные колонки
                $sheet->setCellValueExplicit([$col++, $row], $item['id'], DataType::TYPE_NUMERIC);
                $sheet->setCellValue([$col++, $row], $item['sku'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['code'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['barcode'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['name']);
                $sheet->setCellValue([$col++, $row], $item['brand_name'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['category_name'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['category_path'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['model_name'] ?? '');
                $sheet->setCellValueExplicit([$col++, $row], $item['price'], DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit([$col++, $row], $item['base_price'], DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit([$col++, $row], $item['stock'], DataType::TYPE_NUMERIC);
                $sheet->setCellValue([$col++, $row], $item['stock'] > 0 ? 'Да' : 'Нет');
                $sheet->setCellValue([$col++, $row], mb_substr(strip_tags($item['description'] ?? ''), 0, 500));
                $sheet->setCellValue([$col++, $row], $item['short_description'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['meta_title'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['meta_description'] ?? '');
                $sheet->setCellValue([$col++, $row], $item['main_image'] ?? '');
                $sheet->setCellValue([$col++, $row], implode(', ', $item['additional_images']));
                $sheet->setCellValue([$col++, $row], $item['is_new'] ? 'Да' : 'Нет');
                $sheet->setCellValue([$col++, $row], $item['is_bestseller'] ? 'Да' : 'Нет');
                $sheet->setCellValue([$col++, $row], $item['url'] ?? '');

                // Атрибуты — динамические колонки
                foreach ($item['attributes'] as $attr) {
                    $name = $attr['name'];

                    // Регистрируем новый атрибут если впервые встречаем
                    if (!isset($attrNameIndex[$name])) {
                        $offset = count($attrNameIndex);
                        $attrNameIndex[$name] = $offset;
                        // Записываем заголовок атрибута
                        $sheet->setCellValue([$fixedCount + $offset + 1, 1], $name);
                    }

                    $val = $attr['value'];
                    if ($attr['unit']) {
                        $val .= ' ' . $attr['unit'];
                    }

                    $attrCol = $fixedCount + $attrNameIndex[$name] + 1;
                    $sheet->setCellValue([$attrCol, $row], $val);
                }

                $row++;
            }
        });

        // Стилизуем заголовки (после записи данных — чтобы знать все колонки)
        $totalCols = $fixedCount + count($attrNameIndex);
        $headerRange = 'A1:' . $this->columnLetter($totalCols) . '1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2B5797']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(25);

        // ── Лист 2: Категории ──
        $catSheet = $spreadsheet->createSheet();
        $catSheet->setTitle('Категории');

        $catHeaders = ['ID', 'Название', 'ID родителя', 'Полный путь'];
        foreach ($catHeaders as $i => $h) {
            $catSheet->setCellValue([$i + 1, 1], $h);
        }

        $catSheet->getStyle('A1:D1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2B5797']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $catSheet->freezePane('A2');
        $catSheet->getColumnDimension('A')->setWidth(8);
        $catSheet->getColumnDimension('B')->setWidth(30);
        $catSheet->getColumnDimension('C')->setWidth(12);
        $catSheet->getColumnDimension('D')->setWidth(50);

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

        // Возвращаемся на первый лист
        $spreadsheet->setActiveSheetIndex(0);

        // Записываем — без пересчёта формул (нет формул)
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($stream);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
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
