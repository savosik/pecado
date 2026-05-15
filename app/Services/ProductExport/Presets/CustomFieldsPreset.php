<?php

namespace App\Services\ProductExport\Presets;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\ExportFormat;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\FieldRegistry;
use App\Services\ProductExport\StepTimer;
use App\Services\ProductExport\TimerAware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Адаптер для кастомных выгрузок (preset = null) поверх асинхронного пайплайна.
 *
 * Why: раньше кастомные выгрузки выполнялись синхронно через
 * ProductExportService::generate(), что для каталога в ~5к товаров упиралось в
 * PHP-таймаут 30s и memory_limit. Этот пресет реализует ту же логику полей и
 * модификаторов, но с чанк-выборкой и записью в storage/app/exports/{hash},
 * так что ProductExportDownloadController может отдать готовый файл и
 * дёргать GenerateProductExportJob для прогрева кэша.
 *
 * Поддерживает 4 формата ($export->format): CSV / JSON / XML / XLS.
 */
class CustomFieldsPreset implements PresetInterface, TimerAware
{
    protected const CHUNK_SIZE = 200;

    /** @var array<int, string> */
    protected array $fieldKeys = [];

    /** @var array<string, string> */
    protected array $labels = [];

    /** @var array<string, array<string, mixed>> */
    protected array $modifiers = [];

    /** @var array<int, \App\Models\Currency> */
    protected array $currencyCache = [];

    protected int $rowsProcessed = 0;

    /**
     * Таймер замеров этапов; ставится Generator-ом перед writeToStream.
     */
    protected ?StepTimer $timer = null;

    public function __construct(
        protected FieldRegistry $registry,
        protected PriceServiceInterface $priceService,
        protected StockServiceInterface $stockService,
        protected \App\Contracts\Currency\CurrencyConversionServiceInterface $currencyService,
    ) {}

    public function setStepTimer(?StepTimer $timer): void
    {
        $this->timer = $timer;
    }

    protected function timer(): StepTimer
    {
        return $this->timer ??= new StepTimer;
    }

    public function key(): string
    {
        return 'custom_fields';
    }

    public function name(): string
    {
        return 'Кастомные поля';
    }

    public function description(): string
    {
        return 'Кастомная выгрузка с произвольным набором колонок (внутренний адаптер).';
    }

    public function fileExtension(): string
    {
        return $this->currentExportFormat()?->extension() ?? 'csv';
    }

    public function mimeType(): string
    {
        return match ($this->currentExportFormat()) {
            ExportFormat::JSON => 'application/json; charset=utf-8',
            ExportFormat::XML => 'application/xml; charset=utf-8',
            ExportFormat::XLS => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'text/csv; charset=utf-8',
        };
    }

    public function color(): string
    {
        return 'gray';
    }

    public function icon(): string
    {
        return 'Settings2';
    }

    public function getRowsProcessed(): int
    {
        return $this->rowsProcessed;
    }

    /**
     * Текущий формат экспорта — выставляется на время одного writeToStream(),
     * чтобы fileExtension()/mimeType() могли отдать корректное значение.
     */
    protected ?ExportFormat $currentExportFormat = null;

    protected function currentExportFormat(): ?ExportFormat
    {
        return $this->currentExportFormat;
    }

    public function writeToStream($stream, ProductExport $export): void
    {
        $this->currentExportFormat = $export->format;
        $this->rowsProcessed = 0;
        $this->parseFields($export->fields ?? []);
        $this->labels = $this->registry->getFieldLabels($this->fieldKeys);
        // Кастомные labels поверх дефолтов
        foreach ($export->fields ?? [] as $field) {
            if (is_array($field) && ! empty($field['key']) && ! empty($field['label'])) {
                $this->labels[$field['key']] = $field['label'];
            }
        }

        try {
            match ($export->format) {
                ExportFormat::CSV => $this->writeCsv($stream, $export),
                ExportFormat::JSON => $this->writeJson($stream, $export),
                ExportFormat::XML => $this->writeXml($stream, $export),
                ExportFormat::XLS => $this->writeXls($stream, $export),
            };
        } finally {
            $this->currentExportFormat = null;
        }
    }

    public function generate(ProductExport $export): StreamedResponse
    {
        $filename = "export_{$export->id}_".now()->format('Y-m-d').".{$this->fileExtension()}";

        return new StreamedResponse(function () use ($export) {
            $stream = fopen('php://output', 'w');
            $this->writeToStream($stream, $export);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $this->mimeType(),
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ─── Format writers ────────────────────────────

    protected function writeCsv($stream, ProductExport $export): void
    {
        // UTF-8 BOM для корректного отображения в Excel
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_values($this->labels), ';');

        $this->eachChunk($export, function (Collection $rows) use ($stream) {
            foreach ($rows as $row) {
                $line = [];
                foreach ($this->fieldKeys as $key) {
                    $line[] = $this->normalizeValue($row[$key] ?? '');
                }
                fputcsv($stream, $line, ';');
            }
        });
    }

    protected function writeJson($stream, ProductExport $export): void
    {
        // Why стрима: предыдущая реализация копила весь массив товаров в
        // PHP-массиве и делала один fwrite() в конце — для каталога ~5к
        // товаров × 250 полей пик памяти доходил до 260+ МБ, на грани
        // soft-лимита worker'а (--memory=256). Стримим JSON поэлементно,
        // чтобы пик памяти упирался в один чанк (CHUNK_SIZE товаров),
        // а tmp-файл реально рос на диске и наблюдатель видел прогресс.
        //
        // Why label as JSON key: CSV/XLS уже используют пользовательский
        // label из конструктора как заголовок колонки. JSON же до этого
        // отдавал технический ключ FieldRegistry (`brand.name`,
        // `attribute.c-feromonami`), что выглядело как утечка внутренней
        // схемы. Теперь label становится ключом — UI-конструктор работает
        // одинаково для всех форматов.
        fwrite($stream, "[\n");
        $first = true;

        $this->eachChunk($export, function (Collection $rows) use ($stream, &$first) {
            foreach ($rows as $row) {
                $labeled = [];
                foreach ($this->fieldKeys as $key) {
                    $value = $row[$key] ?? null;
                    if (str_starts_with($key, 'attribute.') && $value === null) {
                        continue;
                    }
                    $jsonKey = $this->labels[$key] ?? $key;
                    $labeled[$jsonKey] = $value;
                }

                $json = json_encode($labeled, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $indented = preg_replace('/^/m', '    ', $json);

                if (! $first) {
                    fwrite($stream, ",\n");
                }
                fwrite($stream, $indented);
                $first = false;
            }
        }, native: true);

        fwrite($stream, "\n]");
    }

    protected function writeXml($stream, ProductExport $export): void
    {
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startElement('products');

        $this->eachChunk($export, function (Collection $rows) use ($xml, $stream) {
            foreach ($rows as $row) {
                $xml->startElement('product');
                foreach ($this->fieldKeys as $key) {
                    $value = $row[$key] ?? null;
                    if (str_starts_with($key, 'attribute.') && $value === null) {
                        continue;
                    }
                    $xmlKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                    $this->writeXmlValue($xml, $xmlKey, $value);
                }
                $xml->endElement();
            }
            // Сбрасываем буфер чанка на диск — иначе для больших каталогов
            // вся выгрузка останется в памяти XMLWriter до flush().
            fwrite($stream, $xml->outputMemory(true));
        }, native: true);

        $xml->endElement();
        $xml->endDocument();
        fwrite($stream, $xml->outputMemory(true));
    }

    /**
     * Записать одно поле в XML, учитывая что значение может быть скаляром,
     * списком (numeric-keyed array) или ассоциативным объектом.
     *
     * Скаляр: <key>value</key>
     * Список: <key><item>a</item><item>b</item></key>
     * Объект: <key><id>1</id><name>...</name></key> (если ключи валидны для XML)
     *         либо <key><item key="..."><id>...</id></item></key> для сложных кейсов.
     */
    protected function writeXmlValue(\XMLWriter $xml, string $elementName, mixed $value): void
    {
        $xml->startElement($elementName);

        if (is_array($value)) {
            $isList = array_is_list($value);
            foreach ($value as $k => $v) {
                if ($isList) {
                    $xml->startElement('item');
                    if (is_array($v)) {
                        // Вложенный объект (например warehouse {id, name, quantity})
                        foreach ($v as $subKey => $subVal) {
                            $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $subKey);
                            $xml->writeElement($safeKey, $this->normalizeValue($subVal));
                        }
                    } else {
                        $xml->text($this->normalizeValue($v));
                    }
                    $xml->endElement();
                } else {
                    // Ассоциативный массив — пишем под ключом через item key="..."
                    // (имя элемента может быть с кириллицей или спецсимволами,
                    // что недопустимо в XML-теге, а атрибут такое допускает).
                    $xml->startElement('item');
                    $xml->writeAttribute('key', (string) $k);
                    $xml->text($this->normalizeValue($v));
                    $xml->endElement();
                }
            }
        } else {
            $xml->text($this->normalizeValue($value ?? ''));
        }

        $xml->endElement();
    }

    protected function writeXls($stream, ProductExport $export): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Товары');

        $col = 1;
        foreach (array_values($this->labels) as $label) {
            $sheet->setCellValue([$col, 1], $label);
            $col++;
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($this->labels));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

        $rowNum = 2;
        $this->eachChunk($export, function (Collection $rows) use ($sheet, &$rowNum) {
            foreach ($rows as $row) {
                $col = 1;
                foreach ($this->fieldKeys as $key) {
                    $sheet->setCellValue([$col, $rowNum], $this->normalizeValue($row[$key] ?? ''));
                    $col++;
                }
                $rowNum++;
            }
        });

        for ($c = 1; $c <= count($this->labels); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        // PhpSpreadsheet держит весь Spreadsheet в памяти и собирает XLSX на save() —
        // на больших каталогах это самая тяжёлая часть генерации XLS. Замеряем
        // отдельно, чтобы видеть её вес в steps_json. В PR 2 этот блок планируется
        // заменить на OpenSpout (streaming writer).
        $this->timer()->measure('write_format', function () use ($spreadsheet, $stream) {
            $writer = new Xlsx($spreadsheet);
            $writer->save($stream);
        });
    }

    // ─── Data plumbing ─────────────────────────────

    protected function parseFields(array $fields): void
    {
        $this->fieldKeys = [];
        $this->modifiers = [];

        foreach ($fields as $field) {
            if (is_string($field)) {
                $this->fieldKeys[] = $field;
            } elseif (is_array($field) && ! empty($field['key'])) {
                $this->fieldKeys[] = $field['key'];
                if (! empty($field['modifiers']) && is_array($field['modifiers'])) {
                    $this->modifiers[$field['key']] = $field['modifiers'];
                }
            }
        }
    }

    /**
     * Чанк-обработка продуктов: фильтры + eager-load по выбранным полям.
     *
     * Внутри замеряются map_rows / write_format, снаружи — chunks_total.
     * Разница chunks_total - sum остальных ≈ время загрузки чанков
     * (query+eager_load). Кастомные пресеты не строят отдельных
     * price_map / stock_map — эти данные берутся в Field::getValue()
     * через PriceService / StockService, и их время попадает в map_rows.
     *
     * @param  callable(Collection<int, array>): void  $callback
     * @param  bool  $native  Если true — извлекаем значения через nativeValue()
     *                        и применяем модификаторы в native-режиме (используется
     *                        для JSON/XML, где нужны нативные массивы). Default false
     *                        для CSV/XLS — там путь legacy через getValue().
     */
    protected function eachChunk(ProductExport $export, callable $callback, bool $native = false): void
    {
        $timer = $this->timer();

        $clientUser = $export->client_user_id
            ? User::with('region')->find($export->client_user_id)
            : null;

        $relations = $this->registry->eagerLoadFor($this->fieldKeys);

        $query = $this->buildQuery($export->filters ?? []);
        if (! empty($relations)) {
            $query->with($relations);
        }

        $endChunks = $timer->start('chunks_total');
        $query->chunk(static::CHUNK_SIZE, function (Collection $products) use ($clientUser, $callback, $native, $timer) {
            $rows = $timer->measure('map_rows', fn () => $products->map(fn (Product $p) => $native
                ? $this->extractRowNative($p, $clientUser)
                : $this->extractRow($p, $clientUser)));
            $this->rowsProcessed += $rows->count();
            $timer->measure('write_format', fn () => $callback($rows));
        });
        $endChunks();
    }

    /**
     * Поддерживает иерархический формат фильтров и плоский, как
     * в ProductExportService::buildQuery.
     */
    protected function buildQuery(array $filters): Builder
    {
        // Делегируем существующему ProductExportService — там полная логика
        // вложенных групп AND/OR, привязки к FieldRegistry и т.п.
        /** @var \App\Services\ProductExportService $service */
        $service = app(\App\Services\ProductExportService::class);

        return $service->buildQuery($filters);
    }

    protected function extractRow(Product $product, ?User $clientUser): array
    {
        $row = [];
        foreach ($this->fieldKeys as $key) {
            $field = $this->registry->resolve($key);
            $value = $field?->getValue($product, $clientUser);

            if ($field && ! empty($this->modifiers[$key])) {
                $value = $this->applyModifiers($value, $field->modifierType(), $this->modifiers[$key]);
            }

            $row[$key] = $value;
        }

        return $row;
    }

    /**
     * Извлечение строки в native-режиме (для JSON/XML).
     * Берём значения через nativeValue() — multi-value поля вернут массивы.
     * Модификаторы применяются с флагом native=true: multi_value становится
     * no-op (массив проходит насквозь), substring пропускает массивы.
     */
    protected function extractRowNative(Product $product, ?User $clientUser): array
    {
        $row = [];
        foreach ($this->fieldKeys as $key) {
            $field = $this->registry->resolve($key);
            $value = $field?->nativeValue($product, $clientUser);

            if ($field && ! empty($this->modifiers[$key])) {
                $value = $this->applyModifiers($value, $field->modifierType(), $this->modifiers[$key], native: true);
            }

            $row[$key] = $value;
        }

        return $row;
    }

    // ─── Modifier logic (зеркально к ProductExportService) ──────────

    protected const SEPARATOR_MAP = [
        'comma' => ', ',
        'comma_tight' => ',',
        'semicolon' => '; ',
        'semicolon_tight' => ';',
        'pipe' => ' | ',
        'newline' => "\n",
        'slash' => ' / ',
        'slash_tight' => '/',
    ];

    /**
     * @param  bool  $native  В native-режиме (JSON/XML) multi_value-модификатор
     *                        становится no-op — массив проходит насквозь без
     *                        склейки. substring пропускает массивы (см. guard ниже).
     */
    protected function applyModifiers(mixed $value, ?string $modifierType, array $modifiers, bool $native = false): mixed
    {
        if (! $modifierType) {
            return $this->applySubstringModifier($value, $modifiers);
        }

        $result = match ($modifierType) {
            'boolean' => $this->applyBooleanModifier($value, $modifiers),
            'price' => $this->applyPriceModifier($value, $modifiers),
            'numeric' => $this->applyNumericModifier($value, $modifiers),
            'multi_value' => $native ? $value : $this->applyMultiValueModifier($value, $modifiers),
            'date' => $this->applyDateModifier($value, $modifiers),
            default => $value,
        };

        return $this->applySubstringModifier($result, $modifiers);
    }

    protected function applySubstringModifier(mixed $value, array $modifiers): mixed
    {
        // Массивы пропускаем без изменений — substring применим только к строкам.
        // Why: native-режим JSON/XML может прокинуть сюда массив (multi-value
        // поля), и попытка `(string) $array` дала бы "Array" + Warning.
        if (is_array($value)) {
            return $value;
        }
        if (! isset($modifiers['substring_length'])) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $value;
        }
        $str = is_string($value) ? $value : (string) $value;
        $start = (int) ($modifiers['substring_start'] ?? 0);

        return mb_substr($str, $start, (int) $modifiers['substring_length']);
    }

    protected function applyBooleanModifier(mixed $value, array $modifiers): string
    {
        return $value
            ? ($modifiers['true_value'] ?? 'Да')
            : ($modifiers['false_value'] ?? 'Нет');
    }

    protected function applyPriceModifier(mixed $value, array $modifiers): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        $price = (float) $value;
        $currencyId = $modifiers['currency_id'] ?? null;
        if (! empty($currencyId)) {
            $currency = $this->resolveCurrency((int) $currencyId);
            if ($currency && ! $currency->is_base) {
                $price = $this->currencyService->convertFromBase($price, $currency);
            }
        }

        $result = round($this->applyArithmetic($price, $modifiers), 2);

        if (! empty($modifiers['integer_if_whole']) && abs($result - round($result)) < 0.005) {
            return (int) round($result);
        }

        return $result;
    }

    protected function applyNumericModifier(mixed $value, array $modifiers): mixed
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $value;
        }

        $isIntInput = is_int($value)
            || (is_string($value) && ctype_digit(ltrim($value, '-')));

        $result = $this->applyArithmetic((float) $value, $modifiers);

        if (! empty($modifiers['integer_if_whole']) && abs($result - round($result)) < 0.005) {
            return (int) round($result);
        }

        return $isIntInput ? (int) round($result) : round($result, 2);
    }

    protected const SOURCE_SEPARATOR_REGEX = [
        'comma' => '/\s*,\s*/',
        'semicolon' => '/\s*;\s*/',
        'pipe' => '/\s*\|\s*/',
        'slash' => '/\s*\/\s*/',
        'newline' => '/\s*\n\s*/',
    ];

    protected function applyMultiValueModifier(mixed $value, array $modifiers): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $raw = $modifiers['separator'] ?? 'comma';
        $separator = (self::SEPARATOR_MAP[$raw] ?? $raw) ?: ', ';

        $source = $modifiers['source_separator'] ?? 'comma';
        $sourceRegex = self::SOURCE_SEPARATOR_REGEX[$source] ?? self::SOURCE_SEPARATOR_REGEX['comma'];

        $parts = preg_split($sourceRegex, $value);

        return implode($separator, $parts);
    }

    protected function applyDateModifier(mixed $value, array $modifiers): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $format = $modifiers['format'] ?? null;
        if (! $format) {
            return $value;
        }
        try {
            $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return $value;
        }

        return $dt->format($format);
    }

    protected function applyArithmetic(float $value, array $modifiers): float
    {
        $multiply = $modifiers['multiply'] ?? null;
        if ($multiply !== null && $multiply !== '') {
            $value *= (float) $multiply;
        }
        $add = $modifiers['add'] ?? null;
        if ($add !== null && $add !== '') {
            $value += (float) $add;
        }

        return $value;
    }

    protected function resolveCurrency(int $id): ?Currency
    {
        if (! isset($this->currencyCache[$id])) {
            $this->currencyCache[$id] = Currency::find($id);
        }

        return $this->currencyCache[$id];
    }

    protected function normalizeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        // Защита от регрессий: CSV/XLS не должны получать массивы (там путь
        // через getValue() — уже строка). Но если кто-то прокинет массив
        // случайно — склеим запятыми, не отдадим "Array".
        if (is_array($value)) {
            return implode(', ', array_map(function ($v) {
                if (is_array($v)) {
                    return json_encode($v, JSON_UNESCAPED_UNICODE);
                }

                return (string) $v;
            }, $value));
        }

        return (string) $value;
    }
}
