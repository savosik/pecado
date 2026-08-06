<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Простой стрим-экспортер табличных данных в XLSX (PhpSpreadsheet).
 *
 * Назначение — лёгкие выгрузки одного листа: позиции заказа, состав отгрузки и т.п.
 * Для больших каталогов используется ProductExport pipeline в App\Services\ProductExport.
 */
class SimpleXlsxExporter
{
    /**
     * Порог, после которого авто-ширина колонок отключается: PhpSpreadsheet
     * меряет каждую ячейку, и на многотысячных выгрузках это стоит дороже,
     * чем сама генерация файла.
     */
    private const AUTOSIZE_MAX_ROWS = 3000;

    /**
     * @param  list<string>  $headers  заголовки колонок
     * @param  iterable<array<int, scalar|null>>  $rows  значения строк
     */
    public function stream(string $filename, array $headers, iterable $rows, ?string $sheetTitle = null): StreamedResponse
    {
        return $this->streamSheets($filename, [
            [
                'title' => $sheetTitle,
                'headers' => $headers,
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * Многолистовая выгрузка: по листу на каждый набор данных.
     * Пустые наборы всё равно получают лист с заголовками — иначе непонятно,
     * то ли разрез пустой, то ли выгрузка его потеряла.
     *
     * @param  list<array{title?: ?string, headers: list<string>, rows: iterable<array<int, scalar|null>>}>  $sheets
     */
    public function streamSheets(string $filename, array $sheets): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setCreator('Pecado')->setTitle($filename);

        $usedTitles = [];
        $isFirst = true;

        foreach ($sheets as $definition) {
            $sheet = $isFirst
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet();
            $isFirst = false;

            $title = $definition['title'] ?? null;
            if ($title) {
                $sheet->setTitle($this->uniqueSheetTitle($title, $usedTitles));
            }

            $this->fillSheet($sheet, $definition['headers'], $definition['rows']);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/u', '_', $filename);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "{$safeName}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    private function fillSheet(Worksheet $sheet, array $headers, iterable $rows): void
    {
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }

        $colsCount = max(1, count($headers));
        $lastColLetter = Coordinate::stringFromColumnIndex($colsCount);
        $sheet->getStyle("A1:{$lastColLetter}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColLetter}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE2E8F0');
        $sheet->freezePane('A2');

        $rowIndex = 2;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $value) {
                // Строку, похожую на число (ИНН, артикул, штрихкод), пишем явно
                // текстом: иначе Excel съедает ведущие нули и разворачивает
                // длинные коды в экспоненту. Числа приходят числами и типом не
                // трогаются — по ним считают итоги.
                if (is_string($value) && $value !== '' && is_numeric($value)) {
                    $sheet->setCellValueExplicit([$i + 1, $rowIndex], $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$i + 1, $rowIndex], $value);
                }
            }
            $rowIndex++;
        }

        $dataRows = $rowIndex - 2;

        for ($i = 1; $i <= $colsCount; $i++) {
            $dimension = $sheet->getColumnDimensionByColumn($i);

            if ($dataRows <= self::AUTOSIZE_MAX_ROWS) {
                $dimension->setAutoSize(true);
            } else {
                $dimension->setWidth($i === 1 ? 42 : 18);
            }
        }
    }

    /**
     * Имя листа: ≤31 символа, без запрещённых Excel-символов и без повторов.
     *
     * @param  array<string, true>  $usedTitles
     */
    private function uniqueSheetTitle(string $title, array &$usedTitles): string
    {
        $base = mb_substr(str_replace(['*', ':', '/', '\\', '?', '[', ']'], ' ', $title), 0, 31);
        $candidate = $base;
        $suffix = 2;

        while (isset($usedTitles[$candidate])) {
            $tail = ' '.$suffix++;
            $candidate = mb_substr($base, 0, 31 - mb_strlen($tail)).$tail;
        }

        $usedTitles[$candidate] = true;

        return $candidate;
    }
}
