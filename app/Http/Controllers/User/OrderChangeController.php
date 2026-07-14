<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\OrderChangeAggregator;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Сводная лента изменений товарного состава заказов клиента —
 * «Изменения заказов» в личном кабинете. Данные строятся свёрткой
 * логов order_change_logs через {@see OrderChangeAggregator}.
 */
class OrderChangeController extends Controller
{
    /** Человекочитаемые метки типов изменения. */
    private const TYPE_LABELS = [
        'added' => 'Добавлен',
        'removed' => 'Выбыл',
        'changed' => 'Изменено количество',
    ];

    public function __construct(
        protected OrderChangeAggregator $aggregator,
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
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

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
            'types' => collect(self::TYPE_LABELS)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values(),
            'exportEnabled' => (bool) config('search-cabinet.export'),
        ]);
    }

    /**
     * Экспорт ленты изменений в CSV/XLSX.
     * GET /cabinet/order-changes/export?format=csv|xlsx
     */
    public function export(Request $request, SimpleCsvExporter $csv, SimpleXlsxExporter $xlsx): StreamedResponse
    {
        abort_unless((bool) config('search-cabinet.export'), 404);

        $format = strtolower((string) $request->input('format', ''));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Допустимые форматы: csv, xlsx.');

        [$rows] = $this->buildRows($request);

        $headers = ['Время', 'Заказ', 'Тип изменения', 'Товар', 'Было', 'Стало'];

        $data = (function () use ($rows) {
            foreach ($rows as $r) {
                yield [
                    $r['changed_at']?->format('d.m.Y H:i') ?? '',
                    $r['order_number'],
                    self::TYPE_LABELS[$r['type']] ?? $r['type'],
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
     * Построить отфильтрованный и отсортированный список движений — общий
     * источник и для таблицы (index), и для экспорта.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function buildRows(Request $request): array
    {
        $user = $request->user();

        $search = trim((string) $request->input('search', ''));
        $types = array_values(array_filter(
            (array) $request->input('type', []),
            fn ($t) => in_array($t, ['added', 'removed', 'changed'], true),
        ));
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereHas('changeLogs', fn ($q) => $q->where('type', 'items_updated'))
            ->with('company')
            ->get();

        $rows = $this->aggregator->flatten($orders);

        $searchLower = mb_strtolower($search);

        $rows = array_values(array_filter($rows, function (array $r) use ($searchLower, $types, $dateFrom, $dateTo) {
            if ($types && ! in_array($r['type'], $types, true)) {
                return false;
            }
            if ($searchLower !== ''
                && mb_stripos($r['order_number'], $searchLower) === false
                && mb_stripos($r['product_name'], $searchLower) === false) {
                return false;
            }
            $date = $r['changed_at']?->toDateString();
            if ($dateFrom && (! $date || $date < $dateFrom)) {
                return false;
            }
            if ($dateTo && (! $date || $date > $dateTo)) {
                return false;
            }

            return true;
        }));

        // Сортировка по времени изменения — новые сверху.
        usort($rows, fn ($a, $b) => ($b['changed_at']?->getTimestamp() ?? 0) <=> ($a['changed_at']?->getTimestamp() ?? 0));

        $context = [
            'search' => $search,
            'type' => $types,
            'date_from' => $dateFrom ?: '',
            'date_to' => $dateTo ?: '',
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
            'type' => $r['type'],
            'type_label' => self::TYPE_LABELS[$r['type']] ?? $r['type'],
            'product_name' => $r['product_name'],
            'slug' => $r['slug'],
            'from' => $r['from'],
            'to' => $r['to'],
        ];
    }
}
