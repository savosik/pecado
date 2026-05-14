<?php

namespace App\Services;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Enums\ExportFormat;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\FieldRegistry;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExportService
{
    /** @var array<int, Currency> Pre-loaded currencies for current export run */
    protected array $currencyCache = [];

    public function __construct(
        protected FieldRegistry $registry,
        protected CurrencyConversionServiceInterface $currencyService,
    ) {}

    /**
     * Available filter fields (delegates to registry).
     */
    public function getAvailableFilters(): array
    {
        return $this->registry->getAvailableFilters();
    }

    /**
     * Available export fields (delegates to registry).
     */
    public function getAvailableFields(): array
    {
        return $this->registry->getAvailableFields();
    }

    /**
     * Build query with hierarchical filters.
     *
     * Supports both hierarchical format { "logic": "and", "conditions": [...] }
     * and flat format [ { "field": ..., "operator": ..., "value": ... }, ... ]
     */
    public function buildQuery(array $filters = []): Builder
    {
        $query = Product::query();

        if (empty($filters)) {
            return $query;
        }

        if (isset($filters['logic'])) {
            $this->applyFilterGroup($query, $filters);
        } else {
            foreach ($filters as $filter) {
                $field = $filter['field'] ?? null;
                $operator = $filter['operator'] ?? null;
                $value = $filter['value'] ?? null;

                if (! $field || ! $operator) {
                    continue;
                }

                $this->applyFieldFilter($query, $field, $operator, $value);
            }
        }

        return $query;
    }

    /**
     * Apply a hierarchical filter group recursively.
     */
    protected function applyFilterGroup(Builder $query, array $group): void
    {
        $logic = strtolower($group['logic'] ?? 'and');
        $conditions = $group['conditions'] ?? [];

        if (empty($conditions)) {
            return;
        }

        $query->where(function (Builder $q) use ($conditions, $logic) {
            foreach ($conditions as $condition) {
                $type = $condition['type'] ?? 'condition';

                if ($type === 'group') {
                    if ($logic === 'or') {
                        $q->orWhere(function (Builder $sq) use ($condition) {
                            $this->applyFilterGroup($sq, $condition);
                        });
                    } else {
                        $q->where(function (Builder $sq) use ($condition) {
                            $this->applyFilterGroup($sq, $condition);
                        });
                    }
                } else {
                    $field = $condition['field'] ?? null;
                    $operator = $condition['operator'] ?? null;
                    $value = $condition['value'] ?? null;

                    if (! $field || ! $operator) {
                        continue;
                    }

                    if ($logic === 'or') {
                        $q->orWhere(function (Builder $sq) use ($field, $operator, $value) {
                            $this->applyFieldFilter($sq, $field, $operator, $value);
                        });
                    } else {
                        $q->where(function (Builder $sq) use ($field, $operator, $value) {
                            $this->applyFieldFilter($sq, $field, $operator, $value);
                        });
                    }
                }
            }
        });
    }

    /**
     * Apply a single filter using the field registry.
     */
    protected function applyFieldFilter(Builder $query, string $fieldKey, string $operator, mixed $value): void
    {
        // Legacy attribute filter with nested value
        if ($fieldKey === 'attribute' && is_array($value) && isset($value['attribute_id'])) {
            $fieldKey = "attr.{$value['attribute_id']}";
            $value = $value['value'] ?? null;
        }

        $field = $this->registry->resolve($fieldKey);
        if ($field) {
            $field->applyFilter($query, $operator, $value);
        }
    }

    /**
     * Normalize fields format.
     * Supports both legacy string[] and new {key, label, modifiers}[] formats.
     * Returns [keys[], customLabels[], modifiersMap[]].
     */
    protected function normalizeFields(array $fields): array
    {
        $keys = [];
        $customLabels = [];
        $modifiers = [];

        foreach ($fields as $field) {
            if (is_string($field)) {
                $keys[] = $field;
            } elseif (is_array($field) && isset($field['key'])) {
                $keys[] = $field['key'];
                if (! empty($field['label'])) {
                    $customLabels[$field['key']] = $field['label'];
                }
                if (! empty($field['modifiers']) && is_array($field['modifiers'])) {
                    $modifiers[$field['key']] = $field['modifiers'];
                }
            }
        }

        return [$keys, $customLabels, $modifiers];
    }

    /**
     * Fetch products data with selected fields.
     */
    public function fetchData(ProductExport $export, ?int $limit = null): Collection
    {
        $query = $this->buildQuery($export->filters ?? []);

        [$fieldKeys, , $modifiers] = $this->normalizeFields($export->fields ?? []);
        $relations = $this->registry->eagerLoadFor($fieldKeys);
        if (! empty($relations)) {
            $query->with($relations);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $products = $query->get();

        $clientUser = $export->client_user_id
            ? User::with('region')->find($export->client_user_id)
            : null;

        return $products->map(function ($product) use ($fieldKeys, $modifiers, $clientUser) {
            return $this->extractFields($product, $fieldKeys, $modifiers, $clientUser);
        });
    }

    /**
     * Get count of matched products.
     */
    public function getCount(array $filters = []): int
    {
        return $this->buildQuery($filters)->count();
    }

    /**
     * Extract field values from a product using registry.
     * Applies modifiers (boolean labels, price currency, multi-value separators).
     */
    protected function extractFields(Product $product, array $fieldKeys, array $modifiers = [], ?User $clientUser = null): array
    {
        $row = [];

        foreach ($fieldKeys as $fieldKey) {
            $field = $this->registry->resolve($fieldKey);
            $value = $field?->getValue($product, $clientUser);
            $fieldModifiers = $modifiers[$fieldKey] ?? [];

            if ($field && ! empty($fieldModifiers)) {
                $value = $this->applyModifiers($value, $field->modifierType(), $fieldModifiers);
            }

            $row[$fieldKey] = $value;
        }

        return $row;
    }

    /**
     * Apply modifiers to a raw field value.
     */
    protected function applyModifiers(mixed $value, ?string $modifierType, array $modifiers): mixed
    {
        // substring — пост-модификатор, работает поверх любого основного
        // типа и даже без него (для строковых полей вроде external_id,
        // category.external_id). Поэтому раннего return по $modifierType нет.
        if (! $modifierType) {
            return $this->applySubstringModifier($value, $modifiers);
        }

        $result = match ($modifierType) {
            'boolean' => $this->applyBooleanModifier($value, $modifiers),
            'price' => $this->applyPriceModifier($value, $modifiers),
            'numeric' => $this->applyNumericModifier($value, $modifiers),
            'multi_value' => $this->applyMultiValueModifier($value, $modifiers),
            'date' => $this->applyDateModifier($value, $modifiers),
            default => $value,
        };

        // substring — общий пост-модификатор для любого типа: партнёры иногда
        // ждут UUID обрезанным до фиксированной длины (например, sex-opt пишет
        // category_code = 16 символов UUID). Применяется к строковому
        // результату после основного модификатора.
        return $this->applySubstringModifier($result, $modifiers);
    }

    protected function applySubstringModifier(mixed $value, array $modifiers): mixed
    {
        if (! isset($modifiers['substring_length'])) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $value;
        }
        $str = is_string($value) ? $value : (string) $value;
        $start = (int) ($modifiers['substring_start'] ?? 0);
        $length = (int) $modifiers['substring_length'];

        return mb_substr($str, $start, $length);
    }

    protected function applyBooleanModifier(mixed $value, array $modifiers): string
    {
        $trueLabel = $modifiers['true_value'] ?? 'Да';
        $falseLabel = $modifiers['false_value'] ?? 'Нет';

        return $value ? $trueLabel : $falseLabel;
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

        // Партнёрские прайсы часто пишут целые цены без `.00` — отдаём int,
        // когда математически округление сходится. Применяется и к ценовому,
        // и к числовому модификатору (там — рядом).
        if (! empty($modifiers['integer_if_whole']) && abs($result - round($result)) < 0.005) {
            return (int) round($result);
        }

        return $result;
    }

    /**
     * Числовой модификатор: умножение и сложение для не-ценовых полей
     * (остатки, габариты, проценты, числовые атрибуты).
     *
     * Why: округление зависит от типа исходного значения — int → int (остатки
     * должны оставаться целыми), float → 2 знака. Так пользователь, накручивая
     * × 0.95 на total_stock, получит понятное целое число, а на weight_gross —
     * корректные граммы.
     */
    protected function applyNumericModifier(mixed $value, array $modifiers): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! is_numeric($value)) {
            return $value;
        }

        $isIntInput = is_int($value)
            || (is_string($value) && ctype_digit(ltrim($value, '-')));

        $result = $this->applyArithmetic((float) $value, $modifiers);

        // Флаг `integer_if_whole` — для партнёров, которым нужно "980" вместо
        // "980.00" в выгрузке (например, sex-opt-формат пишет цены целыми, если
        // там нет копеек). Без флага — стандартное поведение: int → int,
        // float → 2 знака.
        if (! empty($modifiers['integer_if_whole']) && abs($result - round($result)) < 0.005) {
            return (int) round($result);
        }

        return $isIntInput ? (int) round($result) : round($result, 2);
    }

    /**
     * Применить multiply/add к числовому значению. Порядок: × multiply, затем + add.
     */
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

    /**
     * Коды разделителей, которые принимаются с фронта.
     *
     * Why: Laravel middleware TrimStrings обрезает все строковые входящие значения
     * (включая `\n`, `\r`, `\t` и пробелы по краям), поэтому отправлять с фронта
     * сырые символы-разделители нельзя — `"\n"` превратится в `""`, `" | "` в `"|"`.
     * Фронт шлёт код, бэкенд маппит его на реальный разделитель.
     */
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
     * Регулярки для исходных разделителей — что искать в строке от поля,
     * чтобы разбить на части. По умолчанию запятая, как пишут multi_value-поля
     * (barcodes, additional_images, warehouse_names и т.п.).
     */
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
        // Mapped code → реальный разделитель; legacy raw-значение пропускаем как есть;
        // пустую строку (после TrimStrings) ловим финальным fallback на запятую.
        $separator = (self::SEPARATOR_MAP[$raw] ?? $raw) ?: ', ';

        // Поле может писать значение не через запятую: CategoryPathField пишет
        // через " / ", может прилететь pipe-список и т.д. Параметр
        // `source_separator` определяет регулярку для разбиения.
        $source = $modifiers['source_separator'] ?? 'comma';
        $sourceRegex = self::SOURCE_SEPARATOR_REGEX[$source] ?? self::SOURCE_SEPARATOR_REGEX['comma'];

        $parts = preg_split($sourceRegex, $value);

        return implode($separator, $parts);
    }

    /**
     * Форматирование дат: принимает Carbon / DateTime / ISO-строку, возвращает
     * строку по PHP-формату. Why: партнёры просят DD.MM.YYYY HH:MM:SS вместо
     * нашего дефолта `Y-m-d H:i:s`, и проще дать модификатор, чем плодить
     * field-варианты на каждый формат.
     */
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
            $dt = $value instanceof \DateTimeInterface
                ? $value
                : new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return $value;
        }

        return $dt->format($format);
    }

    /**
     * Get human-readable labels for selected fields.
     * Custom labels from {key, label} format override registry defaults.
     */
    public function getFieldLabels(array $fields): array
    {
        [$fieldKeys, $customLabels] = $this->normalizeFields($fields);
        $registryLabels = $this->registry->getFieldLabels($fieldKeys);

        // Custom labels override registry defaults
        foreach ($customLabels as $key => $label) {
            $registryLabels[$key] = $label;
        }

        return $registryLabels;
    }

    /**
     * Generate the export file and return a StreamedResponse.
     */
    public function generate(ProductExport $export): StreamedResponse
    {
        $data = $this->fetchData($export);
        $fields = $export->fields ?? [];
        $labels = $this->getFieldLabels($fields);

        $export->update(['last_downloaded_at' => now()]);

        $format = $export->format;
        $filename = $this->generateFilename($export);

        return match ($format) {
            ExportFormat::JSON => $this->generateJson($data, $labels, $filename),
            ExportFormat::CSV => $this->generateCsv($data, $labels, $filename),
            ExportFormat::XML => $this->generateXml($data, $labels, $filename),
            ExportFormat::XLS => $this->generateXls($data, $labels, $filename),
        };
    }

    /**
     * Preview: return first N rows of data + total count.
     */
    public function preview(array $filters, array $fields, ?int $clientUserId = null, int $limit = 20): array
    {
        $query = $this->buildQuery($filters);
        $total = $query->count();

        $export = new ProductExport;
        $export->filters = $filters;
        $export->fields = $fields;
        $export->client_user_id = $clientUserId;

        $data = $this->fetchData($export, $limit);
        $labels = $this->getFieldLabels($fields);

        return [
            'total' => $total,
            'data' => $data->values()->toArray(),
            'labels' => $labels,
        ];
    }

    // ─── Format generators ────────────────────────

    protected function generateFilename(ProductExport $export): string
    {
        $slug = \Illuminate\Support\Str::slug($export->name, '_');
        $date = now()->format('Y-m-d');
        $ext = $export->format->extension();

        return "export_{$slug}_{$date}.{$ext}";
    }

    protected function generateJson(Collection $data, array $labels, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($data) {
            $filtered = $data->map(function (array $row) {
                return array_filter($row, function ($value, $key) {
                    // Убираем атрибуты с null значением
                    if (str_starts_with($key, 'attribute.') && $value === null) {
                        return false;
                    }

                    return true;
                }, ARRAY_FILTER_USE_BOTH);
            });
            echo json_encode($filtered->values()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    protected function generateCsv(Collection $data, array $labels, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($data, $labels) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, array_values($labels), ';');

            foreach ($data as $row) {
                $line = [];
                foreach (array_keys($labels) as $key) {
                    $value = $row[$key] ?? '';
                    $line[] = $this->normalizeExportValue($value);
                }
                fputcsv($output, $line, ';');
            }

            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    protected function generateXml(Collection $data, array $labels, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($data, $labels) {
            $xml = new \XMLWriter;
            $xml->openURI('php://output');
            $xml->startDocument('1.0', 'UTF-8');
            $xml->setIndent(true);
            $xml->setIndentString('  ');

            $xml->startElement('products');

            foreach ($data as $row) {
                $xml->startElement('product');
                foreach (array_keys($labels) as $key) {
                    $value = $row[$key] ?? null;

                    // Пропускаем атрибуты с null значением
                    if (str_starts_with($key, 'attribute.') && $value === null) {
                        continue;
                    }

                    $value = $this->normalizeExportValue($value ?? '');
                    $xmlKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                    $xml->writeElement($xmlKey, $value);
                }
                $xml->endElement();
            }

            $xml->endElement();
            $xml->endDocument();
            $xml->flush();
        }, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    protected function generateXls(Collection $data, array $labels, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($data, $labels) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Товары');

            $col = 1;
            foreach (array_values($labels) as $label) {
                $sheet->setCellValue([$col, 1], $label);
                $col++;
            }

            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($labels));
            $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

            $rowNum = 2;
            foreach ($data as $row) {
                $col = 1;
                foreach (array_keys($labels) as $key) {
                    $value = $row[$key] ?? '';
                    $value = $this->normalizeExportValue($value);
                    $sheet->setCellValue([$col, $rowNum], $value);
                    $col++;
                }
                $rowNum++;
            }

            $colCount = count($labels);
            for ($c = 1; $c <= $colCount; $c++) {
                $columnID = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    protected function normalizeExportValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
