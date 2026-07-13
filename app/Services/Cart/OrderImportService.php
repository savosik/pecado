<?php

namespace App\Services\Cart;

use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

/**
 * Разбор и резолв позиций «Импорта заказа» в корзину.
 *
 * Источники: два textarea (идентификаторы + количества в столбик) или загруженный
 * файл (XLSX/CSV) с двумя колонками — идентификатор и количество.
 *
 * Идентификатор — это артикул (products.sku), код 1С (products.code) или штрихкод
 * (products.barcode / product_barcodes.barcode). Матч точный, регистронезависимый,
 * батчевый (не N запросов на список).
 */
class OrderImportService
{
    /** Ключевые слова, по которым распознаём строку-заголовок в файле. */
    private const HEADER_KEYWORDS = ['идентиф', 'код', 'артикул', 'штрих', 'кол-во', 'колич', 'quantity', 'qty', 'sku', 'barcode'];

    /**
     * Разобрать два столбца текста (идентификаторы / количества).
     *
     * Строки тримятся, пустые отбрасываются. Если число непустых строк не совпадает —
     * кидает ValidationException.
     *
     * @return array<int, array{identifier: string, quantity: string}>
     */
    public function parseTextColumns(string $identifiers, string $quantities): array
    {
        $ids = $this->nonEmptyLines($identifiers);
        $qtys = $this->nonEmptyLines($quantities);

        if (count($ids) !== count($qtys)) {
            throw ValidationException::withMessages([
                'identifiers' => 'Число строк с идентификаторами ('.count($ids).') не совпадает с числом строк количеств ('.count($qtys).').',
            ]);
        }

        $rows = [];
        foreach ($ids as $i => $identifier) {
            $rows[] = [
                'identifier' => $identifier,
                'quantity' => $qtys[$i],
            ];
        }

        return $rows;
    }

    /**
     * Разобрать загруженный файл (XLSX или CSV/TXT). Первая колонка — идентификатор,
     * вторая — количество. Строка-заголовок пропускается автоматически.
     *
     * @return array<int, array{identifier: string, quantity: string}>
     */
    public function parseFile(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');

        $raw = $ext === 'xlsx'
            ? $this->readXlsxRows($file)
            : $this->readCsvRows($file);

        $rows = [];
        foreach ($raw as $index => $cells) {
            $identifier = trim((string) ($cells[0] ?? ''));
            $quantity = trim((string) ($cells[1] ?? ''));

            if ($identifier === '' && $quantity === '') {
                continue;
            }

            // Первая непустая строка, похожая на заголовок, — пропускаем.
            if ($index === 0 && $this->looksLikeHeader($identifier, $quantity)) {
                continue;
            }

            if ($identifier === '') {
                continue;
            }

            $rows[] = [
                'identifier' => $identifier,
                'quantity' => $quantity,
            ];
        }

        return $rows;
    }

    /**
     * Сопоставить строки с товарами. Дубли по product_id схлопываются суммированием
     * количеств.
     *
     * @param  array<int, array{identifier: string, quantity: string|int}>  $rows
     * @return array{
     *     resolved: array<int, array{product_id: int, identifier: string, name: string, quantity: int}>,
     *     unresolved: array<int, array{identifier: string, quantity: string, reason: string}>
     * }
     */
    public function resolve(array $rows): array
    {
        $identifiers = [];
        foreach ($rows as $row) {
            $id = trim((string) $row['identifier']);
            if ($id !== '') {
                $identifiers[] = $id;
            }
        }

        $lookup = $this->buildLookup($identifiers);

        $resolved = [];   // product_id => ['product_id','identifier','name','quantity']
        $unresolved = [];

        foreach ($rows as $row) {
            $identifier = trim((string) $row['identifier']);
            $quantityRaw = trim((string) $row['quantity']);

            if ($identifier === '') {
                continue;
            }

            if (! preg_match('/^\d+$/', $quantityRaw) || (int) $quantityRaw < 1) {
                $unresolved[] = [
                    'identifier' => $identifier,
                    'quantity' => $quantityRaw,
                    'reason' => 'Неверное количество',
                ];

                continue;
            }

            $quantity = (int) $quantityRaw;
            $products = $lookup->get(mb_strtolower($identifier));

            if ($products === null || $products->isEmpty()) {
                $unresolved[] = [
                    'identifier' => $identifier,
                    'quantity' => $quantityRaw,
                    'reason' => 'Товар не найден',
                ];

                continue;
            }

            if ($products->count() > 1) {
                $unresolved[] = [
                    'identifier' => $identifier,
                    'quantity' => $quantityRaw,
                    'reason' => 'Неоднозначный идентификатор — найдено несколько товаров',
                ];

                continue;
            }

            /** @var Product $product */
            $product = $products->first();
            $pid = (int) $product->id;

            if (isset($resolved[$pid])) {
                $resolved[$pid]['quantity'] += $quantity;
            } else {
                $resolved[$pid] = [
                    'product_id' => $pid,
                    'identifier' => $identifier,
                    'name' => (string) $product->name,
                    'quantity' => $quantity,
                ];
            }
        }

        return [
            'resolved' => array_values($resolved),
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Построить карту «идентификатор (lowercase) → коллекция товаров» батчевыми запросами.
     *
     * @param  array<int, string>  $identifiers
     * @return Collection<string, Collection<int, Product>>
     */
    private function buildLookup(array $identifiers): Collection
    {
        $unique = collect($identifiers)
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values();

        if ($unique->isEmpty()) {
            return collect();
        }

        $needles = $unique->all();

        // Карта lowercased-идентификатор → [product_id => Product]
        /** @var array<string, array<int, Product>> $map */
        $map = [];

        $add = function (string $value, Product $product) use (&$map) {
            $key = mb_strtolower(trim($value));
            if ($key === '') {
                return;
            }
            $map[$key][$product->getKey()] = $product;
        };

        Product::query()
            ->whereIn('sku', $needles)
            ->orWhereIn('code', $needles)
            ->orWhereIn('barcode', $needles)
            ->get(['id', 'name', 'sku', 'code', 'barcode'])
            ->each(function (Product $p) use ($add) {
                if ($p->sku !== null) {
                    $add($p->sku, $p);
                }
                if ($p->code !== null) {
                    $add($p->code, $p);
                }
                if ($p->barcode !== null) {
                    $add($p->barcode, $p);
                }
            });

        ProductBarcode::query()
            ->whereIn('barcode', $needles)
            ->with('product:id,name')
            ->get()
            ->each(function (ProductBarcode $pb) use ($add) {
                if ($pb->product) {
                    $add($pb->barcode, $pb->product);
                }
            });

        // Оставляем в карте только ключи, которые реально запрашивались, и превращаем
        // вложенные массивы в коллекции.
        $result = collect();
        foreach ($needles as $needle) {
            $key = mb_strtolower($needle);
            if (isset($map[$key]) && ! $result->has($key)) {
                $result->put($key, collect(array_values($map[$key])));
            }
        }

        return $result;
    }

    /**
     * Непустые строки текста (после trim).
     *
     * @return array<int, string>
     */
    private function nonEmptyLines(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            fn ($line) => $line !== '',
        ));
    }

    /**
     * Читает CSV/TXT: снимает BOM, сниффит разделитель, возвращает строки как массивы ячеек.
     *
     * @return array<int, array<int, string>>
     */
    private function readCsvRows(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            return [];
        }

        // Снимаем UTF-8 BOM.
        $content = ltrim($content, "\xEF\xBB\xBF");

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = $this->sniffDelimiter($firstLine);

        $rows = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($cells === [null]) {
                continue; // пустая строка
            }
            $rows[] = $cells;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Читает XLSX: активный лист, все строки как массивы ячеек.
     *
     * @return array<int, array<int, string>>
     */
    private function readXlsxRows(UploadedFile $file): array
    {
        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();

        $rows = [];
        foreach ($sheet->toArray(null, true, false, false) as $cells) {
            $rows[] = array_map(fn ($v) => $v === null ? '' : (string) $v, $cells);
        }

        return $rows;
    }

    /**
     * Определяет разделитель CSV по первой строке (`;`, `,` или таб).
     */
    private function sniffDelimiter(string $line): string
    {
        $candidates = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($candidates);
        $best = array_key_first($candidates);

        return $candidates[$best] > 0 ? $best : ';';
    }

    /**
     * Похожа ли первая строка на заголовок: количество не число ИЛИ первая ячейка
     * содержит ключевое слово-заголовок.
     */
    private function looksLikeHeader(string $identifier, string $quantity): bool
    {
        if (! preg_match('/^\d+$/', trim($quantity))) {
            return true;
        }

        $haystack = mb_strtolower($identifier);
        foreach (self::HEADER_KEYWORDS as $kw) {
            if (mb_strpos($haystack, $kw) !== false) {
                return true;
            }
        }

        return false;
    }
}
