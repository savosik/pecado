<?php

namespace App\Services;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Enums\ExportFormat;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\DynamicAttributeField;
use App\Services\ProductExport\FieldRegistry;
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

                if (!$field || !$operator) {
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

                    if (!$field || !$operator) {
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
                if (!empty($field['label'])) {
                    $customLabels[$field['key']] = $field['label'];
                }
                if (!empty($field['modifiers']) && is_array($field['modifiers'])) {
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
        if (!empty($relations)) {
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

            if ($field && !empty($fieldModifiers)) {
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
        if (!$modifierType) return $value;

        return match ($modifierType) {
            'boolean' => $this->applyBooleanModifier($value, $modifiers),
            'price' => $this->applyPriceModifier($value, $modifiers),
            'multi_value' => $this->applyMultiValueModifier($value, $modifiers),
            default => $value,
        };
    }

    protected function applyBooleanModifier(mixed $value, array $modifiers): string
    {
        $trueLabel = $modifiers['true_value'] ?? 'Да';
        $falseLabel = $modifiers['false_value'] ?? 'Нет';

        return $value ? $trueLabel : $falseLabel;
    }

    protected function applyPriceModifier(mixed $value, array $modifiers): mixed
    {
        if ($value === null || $value === '') return '';

        $currencyId = $modifiers['currency_id'] ?? null;

        if (empty($currencyId)) {
            return $value;
        }

        $currency = $this->resolveCurrency((int) $currencyId);

        if (!$currency || $currency->is_base) {
            return $value;
        }

        return round($this->currencyService->convertFromBase((float) $value, $currency), 2);
    }

    protected function resolveCurrency(int $id): ?Currency
    {
        if (!isset($this->currencyCache[$id])) {
            $this->currencyCache[$id] = Currency::find($id);
        }
        return $this->currencyCache[$id];
    }

    protected function applyMultiValueModifier(mixed $value, array $modifiers): mixed
    {
        $separator = $modifiers['separator'] ?? ', ';

        if (!is_string($value)) return $value;

        // Re-split by default separator and re-join with custom one
        $parts = preg_split('/\s*,\s*/', $value);
        return implode($separator, $parts);
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

        $export = new ProductExport();
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
            echo json_encode($data->values()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
                    $line[] = is_bool($value) ? ($value ? 'Да' : 'Нет') : (string) $value;
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
            $xml = new \XMLWriter();
            $xml->openURI('php://output');
            $xml->startDocument('1.0', 'UTF-8');
            $xml->setIndent(true);
            $xml->setIndentString('  ');

            $xml->startElement('products');

            foreach ($data as $row) {
                $xml->startElement('product');
                foreach (array_keys($labels) as $key) {
                    $value = $row[$key] ?? '';
                    if (is_bool($value)) {
                        $value = $value ? 'Да' : 'Нет';
                    }
                    $xmlKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                    $xml->writeElement($xmlKey, (string) $value);
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
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Товары');

            $col = 1;
            foreach (array_values($labels) as $label) {
                $sheet->setCellValueByColumnAndRow($col, 1, $label);
                $col++;
            }

            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($labels));
            $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

            $rowNum = 2;
            foreach ($data as $row) {
                $col = 1;
                foreach (array_keys($labels) as $key) {
                    $value = $row[$key] ?? '';
                    if (is_bool($value)) {
                        $value = $value ? 'Да' : 'Нет';
                    }
                    $sheet->setCellValueByColumnAndRow($col, $rowNum, $value);
                    $col++;
                }
                $rowNum++;
            }

            foreach (range('A', $lastCol) as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
