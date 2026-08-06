<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
    ) {}

    /**
     * Страница «Аналитика» в личном кабинете.
     * GET /cabinet/analytics
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $ctx = AnalyticsContext::forUser($user);
        $filters = AnalyticsFilters::fromRequest($request, $user);

        $payload = $this->buildPayload($ctx, $filters);

        return Inertia::render('User/Cabinet/Analytics/Index', [
            'initial' => $payload,
            'filterOptions' => $this->analytics->filterOptions($user),
        ]);
    }

    /**
     * JSON для XHR-обновления при смене фильтров — без перерендера страницы.
     * GET /cabinet/analytics/data
     */
    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        $ctx = AnalyticsContext::forUser($user);
        $filters = AnalyticsFilters::fromRequest($request, $user);

        return response()->json($this->buildPayload($ctx, $filters));
    }

    /**
     * ABC/XYZ-анализ за последние 12 месяцев.
     * GET /cabinet/analytics/abc-xyz?dimension=brand|category|product
     */
    public function abcXyz(Request $request): JsonResponse
    {
        $user = $request->user();
        $ctx = AnalyticsContext::forUser($user);
        $dimension = (string) $request->input('dimension', 'brand');
        if (! in_array($dimension, ['brand', 'category', 'product'], true)) {
            $dimension = 'brand';
        }

        $filters = AnalyticsFilters::fromRequest($request, $user);

        return response()->json($this->analytics->abcXyz($ctx, $dimension, $filters));
    }

    /**
     * XLSX-выгрузка отчёта по текущим фильтрам. Строки не режутся лимитом UI.
     * Без `section` — все 4 разреза, каждый на своём листе.
     * С `section=brands|categories|contractors|products` — только одна секция со своими колонками.
     * GET /cabinet/analytics/export
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $user = $request->user();
        $ctx = AnalyticsContext::forUser($user);
        $filters = AnalyticsFilters::fromRequest($request, $user);
        $currencyCode = $this->analytics->userCurrency($user)?->code ?? 'RUB';
        $section = (string) $request->input('section', '');

        if ($section === '') {
            return $this->exportAll($ctx, $filters, $currencyCode, $exporter);
        }

        return $this->exportSection($ctx, $filters, $currencyCode, $section, $exporter);
    }

    /**
     * Сводная выгрузка: по листу на разрез, все строки без лимита UI —
     * иначе в файл попадал бы только топ, показанный на экране.
     */
    private function exportAll(AnalyticsContext $ctx, AnalyticsFilters $filters, string $currencyCode, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $sections = [
            'Бренды' => $this->analytics->byBrand($ctx, $filters, null),
            'Категории' => $this->analytics->byCategory($ctx, $filters, null),
            'Контрагенты' => $this->analytics->byContractor($ctx, $filters, null),
            'Товары' => $this->analytics->byProduct($ctx, $filters, null),
        ];

        $sheets = [];

        foreach ($sections as $sectionLabel => $items) {
            $sheets[] = [
                'title' => $sectionLabel,
                'headers' => ['Значение', 'Сумма', 'Штук', 'Поставок', 'Контрагентов', 'Валюта'],
                'rows' => $items->map(fn ($item) => [
                    $item['label'] ?? '',
                    round((float) ($item['amount'] ?? 0), 2),
                    (int) ($item['qty'] ?? 0),
                    (int) ($item['shipments_count'] ?? 0),
                    (int) ($item['contractors_count'] ?? 0),
                    $currencyCode,
                ])->all(),
            ];
        }

        return $exporter->streamSheets('analytics-'.now()->format('Y-m-d-His'), $sheets);
    }

    private function exportSection(AnalyticsContext $ctx, AnalyticsFilters $filters, string $currencyCode, string $section, SimpleXlsxExporter $exporter): StreamedResponse
    {
        [$title, $items, $headers, $mapper] = match ($section) {
            'brands' => [
                'Бренды',
                $this->analytics->byBrand($ctx, $filters, null),
                ['Бренд', 'Поставок', 'Контрагентов', 'Штук', 'Сумма', 'Валюта'],
                fn ($r) => [
                    $r['label'] ?? '',
                    (int) ($r['shipments_count'] ?? 0),
                    (int) ($r['contractors_count'] ?? 0),
                    (int) ($r['qty'] ?? 0),
                    round((float) ($r['amount'] ?? 0), 2),
                    $currencyCode,
                ],
            ],
            'categories' => [
                'Категории',
                $this->analytics->byCategory($ctx, $filters, null),
                ['Категория', 'Поставок', 'Контрагентов', 'Штук', 'Сумма', 'Валюта'],
                fn ($r) => [
                    $r['label'] ?? '',
                    (int) ($r['shipments_count'] ?? 0),
                    (int) ($r['contractors_count'] ?? 0),
                    (int) ($r['qty'] ?? 0),
                    round((float) ($r['amount'] ?? 0), 2),
                    $currencyCode,
                ],
            ],
            'contractors' => [
                'Контрагенты',
                $this->analytics->byContractor($ctx, $filters, null),
                ['Контрагент', 'ИНН', 'Поставок', 'Штук', 'Сумма', 'Валюта'],
                fn ($r) => [
                    $r['label'] ?? '',
                    $r['tax_id'] ?? '',
                    (int) ($r['shipments_count'] ?? 0),
                    (int) ($r['qty'] ?? 0),
                    round((float) ($r['amount'] ?? 0), 2),
                    $currencyCode,
                ],
            ],
            'products' => [
                'Товары',
                $this->analytics->byProduct($ctx, $filters, null),
                ['Товар', 'Артикул', 'Поставок', 'Контрагентов', 'Штук', 'Сумма', 'Валюта'],
                fn ($r) => [
                    $r['label'] ?? '',
                    $r['sku'] ?? '',
                    (int) ($r['shipments_count'] ?? 0),
                    (int) ($r['contractors_count'] ?? 0),
                    (int) ($r['qty'] ?? 0),
                    round((float) ($r['amount'] ?? 0), 2),
                    $currencyCode,
                ],
            ],
            default => abort(422, 'Неизвестная секция'),
        };

        $rows = $items->map($mapper)->all();
        $filename = 'analytics-'.$section.'-'.now()->format('Y-m-d-His');

        return $exporter->stream($filename, $headers, $rows, $title);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AnalyticsContext $ctx, AnalyticsFilters $filters): array
    {
        $currency = $ctx->currency;

        // Разрезы тянем с «запасной» строкой сверх лимита, чтобы понять,
        // что таблица на экране обрезана, и подсказать про XLSX.
        $limit = ShipmentAnalyticsService::UI_LIMIT_DEFAULT;
        $productLimit = ShipmentAnalyticsService::UI_LIMIT_PRODUCTS;

        $breakdowns = [
            'by_brand' => ShipmentAnalyticsService::cap($this->analytics->byBrand($ctx, $filters, $limit + 1), $limit),
            'by_category' => ShipmentAnalyticsService::cap($this->analytics->byCategory($ctx, $filters, $limit + 1), $limit),
            'by_contractor' => ShipmentAnalyticsService::cap($this->analytics->byContractor($ctx, $filters, $limit + 1), $limit),
            'by_product' => ShipmentAnalyticsService::cap($this->analytics->byProduct($ctx, $filters, $productLimit + 1), $productLimit),
        ];

        return [
            'filters' => $filters->toArray(),
            'currency' => [
                'code' => $currency?->code ?? 'RUB',
                'symbol' => $currency?->symbol ?? '₽',
            ],
            'metrics' => $this->analytics->metrics($ctx, $filters),
            'insights' => $this->analytics->insights($ctx, $filters),
            'time_series' => $this->analytics->timeSeries($ctx, $filters),
            'by_brand' => $breakdowns['by_brand']['rows'],
            'by_category' => $breakdowns['by_category']['rows'],
            'by_contractor' => $breakdowns['by_contractor']['rows'],
            'by_product' => $breakdowns['by_product']['rows'],
            'truncation' => array_map(
                fn (array $b) => ['truncated' => $b['truncated'], 'limit' => $b['limit']],
                $breakdowns,
            ),
        ];
    }
}
