<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
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
        $filters = AnalyticsFilters::fromRequest($request, $user);

        $payload = $this->buildPayload($user, $filters);

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
        $filters = AnalyticsFilters::fromRequest($request, $user);

        return response()->json($this->buildPayload($user, $filters));
    }

    /**
     * ABC/XYZ-анализ за последние 12 месяцев.
     * GET /cabinet/analytics/abc-xyz?dimension=brand|category|product
     */
    public function abcXyz(Request $request): JsonResponse
    {
        $user = $request->user();
        $dimension = (string) $request->input('dimension', 'brand');
        if (! in_array($dimension, ['brand', 'category', 'product'], true)) {
            $dimension = 'brand';
        }

        $filters = AnalyticsFilters::fromRequest($request, $user);

        return response()->json($this->analytics->abcXyz($user, $dimension, $filters));
    }

    /**
     * XLSX-выгрузка отчёта по текущим фильтрам.
     * Без `section` — все 4 секции одним листом.
     * С `section=brands|categories|contractors|products` — только одна секция со своими колонками.
     * GET /cabinet/analytics/export
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $user = $request->user();
        $filters = AnalyticsFilters::fromRequest($request, $user);
        $currencyCode = $this->analytics->userCurrency($user)?->code ?? 'RUB';
        $section = (string) $request->input('section', '');

        if ($section === '') {
            return $this->exportAll($user, $filters, $currencyCode, $exporter);
        }

        return $this->exportSection($user, $filters, $currencyCode, $section, $exporter);
    }

    private function exportAll($user, AnalyticsFilters $filters, string $currencyCode, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $headers = ['Группировка', 'Значение', 'Сумма', 'Штук', 'Поставок', 'Контрагентов', 'Валюта'];
        $rows = [];

        $sections = [
            'Бренд' => $this->analytics->byBrand($user, $filters),
            'Категория' => $this->analytics->byCategory($user, $filters),
            'Контрагент' => $this->analytics->byContractor($user, $filters),
            'Товар' => $this->analytics->byProduct($user, $filters),
        ];

        foreach ($sections as $sectionLabel => $items) {
            foreach ($items as $item) {
                $rows[] = [
                    $sectionLabel,
                    $item['label'] ?? '',
                    round((float) ($item['amount'] ?? 0), 2),
                    (int) ($item['qty'] ?? 0),
                    (int) ($item['shipments_count'] ?? 0),
                    (int) ($item['contractors_count'] ?? 0),
                    $currencyCode,
                ];
            }
        }

        return $exporter->stream('analytics-'.now()->format('Y-m-d-His'), $headers, $rows, 'Аналитика');
    }

    private function exportSection($user, AnalyticsFilters $filters, string $currencyCode, string $section, SimpleXlsxExporter $exporter): StreamedResponse
    {
        [$title, $items, $headers, $mapper] = match ($section) {
            'brands' => [
                'Бренды',
                $this->analytics->byBrand($user, $filters),
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
                $this->analytics->byCategory($user, $filters),
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
                $this->analytics->byContractor($user, $filters),
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
                $this->analytics->byProduct($user, $filters),
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
    private function buildPayload($user, AnalyticsFilters $filters): array
    {
        $currency = $this->analytics->userCurrency($user);

        return [
            'filters' => $filters->toArray(),
            'currency' => [
                'code' => $currency?->code ?? 'RUB',
                'symbol' => $currency?->symbol ?? '₽',
            ],
            'metrics' => $this->analytics->metrics($user, $filters),
            'insights' => $this->analytics->insights($user, $filters),
            'time_series' => $this->analytics->timeSeries($user, $filters),
            'by_brand' => $this->analytics->byBrand($user, $filters),
            'by_category' => $this->analytics->byCategory($user, $filters),
            'by_contractor' => $this->analytics->byContractor($user, $filters),
            'by_product' => $this->analytics->byProduct($user, $filters),
        ];
    }
}
