<?php

namespace App\Services\Logistics;

use App\Support\Logistics\ShipmentRow;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Разбор таблицы заданий логисту (XLSX-выгрузка Google-таблицы).
 *
 * Лист на каждый год, внутри — по строке на отгрузку, а между ними строки-даты
 * («20.01.2026 Вторник»), у которых заполнена только первая колонка. Колонки за
 * годы разъезжались: в 2025-м между контрагентом и типом доставки вставили
 * «Количество мест», а в 2026-м подпись первой колонки и вовсе затёрли. Поэтому
 * колонки ищутся по подписям шапки, а не по буквам.
 */
class ShipmentSheetReader
{
    /** Подписи колонок, которые нужны импорту. */
    private const COLUMNS = [
        'client' => 'контрагент',
        'delivery' => 'тип доставки',
        'address' => 'адрес',
        'recipient' => 'получатель',
    ];

    /**
     * Строки отгрузок с указанных листов.
     *
     * @param  list<string>  $sheetNames  названия листов, например «2026 год»
     * @return list<ShipmentRow>
     */
    public function read(string $path, array $sheetNames): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Файл таблицы логиста не найден или недоступен для чтения: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly($sheetNames);

        $book = $reader->load($path);
        $rows = [];

        foreach ($sheetNames as $sheetName) {
            $sheet = $book->getSheetByName($sheetName);

            if ($sheet === null) {
                throw new RuntimeException("В файле нет листа «{$sheetName}».");
            }

            foreach ($this->readSheet($sheet, $sheetName) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Год листа: «2026 год» → 2026.
     */
    public static function sheetYear(string $sheetName): int
    {
        return preg_match('/(\d{4})/', $sheetName, $matches) ? (int) $matches[1] : 0;
    }

    /**
     * @return list<ShipmentRow>
     */
    private function readSheet(Worksheet $sheet, string $sheetName): array
    {
        $columns = $this->locateColumns($sheet);
        $year = self::sheetYear($sheetName);
        $rows = [];

        for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
            $client = $this->cell($sheet, $columns['client'], $line);
            $address = $this->cell($sheet, $columns['address'], $line);
            $recipient = $this->cell($sheet, $columns['recipient'], $line);

            // Строка-разделитель с датой: контрагента и адреса в ней нет.
            if ($client === '' && $address === '' && $recipient === '') {
                continue;
            }

            $rows[] = new ShipmentRow(
                year: $year,
                sheet: $sheetName,
                line: $line,
                client: $client,
                recipient: $recipient,
                address: $address,
                delivery: $this->cell($sheet, $columns['delivery'], $line),
            );
        }

        return $rows;
    }

    /**
     * @return array{client: ?int, delivery: ?int, address: ?int, recipient: ?int}
     */
    private function locateColumns(Worksheet $sheet): array
    {
        $found = array_fill_keys(array_keys(self::COLUMNS), null);

        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($column = 1; $column <= $lastColumn; $column++) {
            $title = $this->normalize($sheet->getCell([$column, 1])->getValue());

            if ($title === '') {
                continue;
            }

            foreach (self::COLUMNS as $key => $needle) {
                if ($found[$key] === null && str_contains($title, $needle)) {
                    $found[$key] = $column;
                }
            }
        }

        // Контрагент стоит во второй колонке на всех листах, но на листе 2026 года
        // его подпись затёрта числом — там опознать колонку по шапке нельзя.
        $found['client'] ??= 2;

        if ($found['address'] === null) {
            throw new RuntimeException("На листе «{$sheet->getTitle()}» не найдена колонка «Адрес».");
        }

        return $found;
    }

    private function cell(Worksheet $sheet, ?int $column, int $line): string
    {
        if ($column === null) {
            return '';
        }

        $value = $sheet->getCell([$column, $line])->getValue();

        return $value === null ? '' : trim((string) $value);
    }

    private function normalize(mixed $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        $text = str_replace(["\u{00A0}", 'ё'], [' ', 'е'], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
