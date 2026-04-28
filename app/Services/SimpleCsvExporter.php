<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Простой стрим-экспортер табличных данных в CSV (UTF-8 + BOM).
 *
 * BOM нужен, чтобы Excel при двойном клике правильно определил кодировку
 * для русских заголовков. Для разделов кабинета (Orders/Returns/Shipments)
 * используется парный с `SimpleXlsxExporter` — единый интерфейс stream().
 */
class SimpleCsvExporter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/u', '_', $filename);

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'wb');
            // UTF-8 BOM для корректного открытия в Excel/Numbers.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($out, array_map(static fn ($v) => $v === null ? '' : (string) $v, $row), ';');
            }
            fclose($out);
        }, "{$safeName}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
