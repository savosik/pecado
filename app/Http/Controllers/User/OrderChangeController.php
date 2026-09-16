<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Order\OrderChangeFeed;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Сводная лента изменений товарного состава заказов клиента —
 * «Изменения заказов» в личном кабинете. Строки строит {@see OrderChangeFeed},
 * общий с клиентским API; здесь — разбор запроса, страница и экспорт.
 */
class OrderChangeController extends Controller
{
    public function __construct(
        protected OrderChangeFeed $feed,
    ) {}

    /**
     * Таблица изменений.
     * GET /cabinet/order-changes
     */
    public function index(Request $request): InertiaResponse
    {
        [$rows, $context] = $this->buildRows($request);

        $perPage = min(max((int) $request->input('per_page', 20), 5), 100);
        $page = max((int) $request->input('page', 1), 1);
        $total = count($rows);
        $slice = OrderChangeFeed::slice($rows, $page, $perPage);

        $paginator = new LengthAwarePaginator(
            array_map(fn (array $r) => $this->transformRow($r), $slice),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('User/Cabinet/OrderChanges/Index', [
            'rows' => $paginator,
            'filters' => $context,
            'types' => collect(OrderChangeFeed::TYPE_LABELS)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values(),
        ]);
    }

    /**
     * Экспорт ленты изменений в CSV/XLSX.
     * GET /cabinet/order-changes/export?format=csv|xlsx
     */
    public function export(Request $request, SimpleCsvExporter $csv, SimpleXlsxExporter $xlsx): StreamedResponse
    {
        // Экспорт ленты изменений доступен всегда (в отличие от общего
        // флага search-cabinet.export для Orders/Returns) — по требованию.
        $format = strtolower((string) $request->input('format', ''));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Допустимые форматы: csv, xlsx.');

        [$rows] = $this->buildRows($request);

        $headers = ['Время', 'Заказ', 'Тип изменения', 'Товар', 'Было', 'Стало'];

        $data = (function () use ($rows) {
            foreach ($rows as $r) {
                yield [
                    $r['changed_at']?->format('d.m.Y H:i') ?? '',
                    $r['order_number'],
                    OrderChangeFeed::TYPE_LABELS[$r['type']] ?? $r['type'],
                    $r['product_name'],
                    $r['from'],
                    $r['to'],
                ];
            }
        })();

        $filename = 'order-changes-'.now()->format('Y-m-d-His');

        return $format === 'csv'
            ? $csv->stream($filename, $headers, $data)
            : $xlsx->stream($filename, $headers, $data, 'Изменения заказов');
    }

    /**
     * Отфильтрованный и отсортированный список движений — общий источник
     * для таблицы (index) и экспорта.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function buildRows(Request $request): array
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'type' => $request->input('type', []),
            'period' => (string) $request->input('period', 'all'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $rows = $this->feed->rows($request->user(), $filters);

        $context = [
            'search' => $filters['search'],
            'type' => OrderChangeFeed::types($filters['type']),
            'period' => $filters['period'],
            'date_from' => $filters['date_from'] ?: '',
            'date_to' => $filters['date_to'] ?: '',
        ];

        return [$rows, $context];
    }

    /**
     * Подготовить строку для фронтенда.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function transformRow(array $r): array
    {
        return [
            'order_id' => $r['order_id'],
            'order_number' => $r['order_number'],
            'changed_at' => $r['changed_at']?->format('d.m.Y H:i'),
            'kind' => $r['kind'],
            'type' => $r['type'],
            'type_label' => OrderChangeFeed::TYPE_LABELS[$r['type']] ?? $r['type'],
            'product_name' => $r['product_name'],
            'slug' => $r['slug'],
            'from' => $r['from'],
            'to' => $r['to'],
        ];
    }
}
