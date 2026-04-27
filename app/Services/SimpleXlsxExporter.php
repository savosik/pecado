<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
     * @param  list<string>  $headers  заголовки колонок
     * @param  iterable<array<int, scalar|null>>  $rows  значения строк
     */
    public function stream(string $filename, array $headers, iterable $rows, ?string $sheetTitle = null): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setCreator('Pecado')->setTitle($filename);

        $sheet = $spreadsheet->getActiveSheet();
        if ($sheetTitle) {
            $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        }

        // Заголовки
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }

        $colsCount = count($headers);
        $lastColLetter = Coordinate::stringFromColumnIndex($colsCount);
        $sheet->getStyle("A1:{$lastColLetter}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColLetter}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE2E8F0');
        $sheet->freezePane('A2');

        // Данные
        $rowIndex = 2;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $value) {
                $sheet->setCellValue([$i + 1, $rowIndex], $value);
            }
            $rowIndex++;
        }

        // Авто-ширина (для небольших выгрузок не дорого)
        for ($i = 1; $i <= $colsCount; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/u', '_', $filename);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "{$safeName}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
