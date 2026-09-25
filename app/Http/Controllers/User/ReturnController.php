<?php

namespace App\Http\Controllers\User;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\ProductReturn;
use App\Models\User;
use App\Services\Returns\ClientReturnPresenter;
use App\Services\Returns\ClientReturnQuery;
use App\Services\Returns\ReturnableShipmentItems;
use App\Services\Returns\ReturnService;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use App\Support\Search\EmptyResultSuggestion;
use App\Support\Search\MatchSourceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Возвраты в кабинете клиента — тонкий транспорт над сервисами возвратов.
 *
 * Выборка, основания и представление живут в `App\Services\Returns\*` и общие
 * с клиентским API v1: здесь только разбор запроса и сборка Inertia-страниц.
 */
class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnService $returnService,
        private readonly ClientReturnQuery $query,
        private readonly ReturnableShipmentItems $bases,
        private readonly ClientReturnPresenter $presenter,
    ) {}

    /**
     * Список возвратов текущего пользователя.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        [$query, $context] = $this->buildIndexQuery($request, $user);
        $search = $context['search'];
        $perPage = $context['per_page'];

        $returns = $query->paginate($perPage)->withQueryString();
        $returns->getCollection()->transform(function ($return) use ($search) {
            $match = MatchSourceResolver::resolve(
                $return,
                $search,
                directFields: [
                    ['field' => 'erp_number', 'source' => 'number'],
                    ['field' => 'uuid', 'source' => 'number'],
                ],
                itemFields: [
                    ['relation' => 'items', 'field' => 'product_name_snapshot', 'source' => 'composition'],
                    ['relation' => 'items', 'field' => 'brand_name_snapshot', 'source' => 'composition'],
                    ['relation' => 'items', 'field' => 'reason_comment', 'source' => 'comment'],
                ],
            );

            return $this->presenter->row($return) + [
                'match_source' => $match['source'],
                'match_snippet' => $match['snippet'],
            ];
        });

        $suggestion = $returns->total() === 0
            ? EmptyResultSuggestion::build($search, $this->activeFiltersForSuggestion($context))
            : null;

        return Inertia::render('User/Cabinet/Returns/Index', [
            'returns' => $returns,
            'filters' => [
                'search' => $search,
                'status' => $context['selected_statuses'],
                'reason' => $context['reasons'],
                'date_from' => $context['date_from'],
                'date_to' => $context['date_to'],
                'amount_from' => $context['amount_from'],
                'amount_to' => $context['amount_to'],
                'sort_by' => $context['sort_by'],
                'sort_order' => $context['sort_order'],
                'per_page' => $perPage,
            ],
            'statuses' => $this->statusOptions(),
            'reasons' => $this->reasonOptions(),
            'exportEnabled' => (bool) config('search-cabinet.export'),
            'suggestion' => $suggestion,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private function activeFiltersForSuggestion(array $context): array
    {
        $labels = [];
        if (! empty($context['selected_statuses'])) {
            $labels['Статус'] = implode(', ', $context['selected_statuses']);
        }
        if (! empty($context['reasons'])) {
            $labels['Причина'] = implode(', ', $context['reasons']);
        }
        if (! empty($context['date_from']) || ! empty($context['date_to'])) {
            $labels['Дата'] = trim(($context['date_from'] ?? '').'…'.($context['date_to'] ?? ''));
        }
        if (! empty($context['amount_from']) || ! empty($context['amount_to'])) {
            $labels['Сумма'] = trim(($context['amount_from'] ?? '').'…'.($context['amount_to'] ?? ''));
        }

        return $labels;
    }

    /**
     * Экспорт текущей выдачи в CSV/XLSX. PR 5.2.
     * GET /cabinet/returns/export?format=csv|xlsx
     */
    public function export(Request $request, SimpleCsvExporter $csv, SimpleXlsxExporter $xlsx): StreamedResponse
    {
        abort_unless((bool) config('search-cabinet.export'), 404);

        $format = strtolower((string) $request->input('format', ''));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Допустимые форматы: csv, xlsx.');

        [$query] = $this->buildIndexQuery($request, $request->user());

        $headers = ['Номер', 'Статус', 'Причина', 'Позиций', 'Сумма', 'Дата'];

        $rows = (function () use ($query) {
            foreach ($query->cursor() as $return) {
                yield [
                    $return->erp_number ?? ('#'.$return->id),
                    $this->presenter->statusLabel($return->status),
                    $this->presenter->reasonLabel($return->items->first()?->reason),
                    $return->items->count(),
                    round((float) $return->total_amount, 2),
                    $return->created_at?->format('d.m.Y H:i'),
                ];
            }
        })();

        $filename = 'returns-'.now()->format('Y-m-d-His');

        return $format === 'csv'
            ? $csv->stream($filename, $headers, $rows)
            : $xlsx->stream($filename, $headers, $rows, 'Возвраты');
    }

    /**
     * Запрос списка и контекст фильтров для страницы/экспорта.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder<ProductReturn>, 1: array<string, mixed>}
     */
    private function buildIndexQuery(Request $request, User $user): array
    {
        $filters = $request->only(['search', 'status', 'reason', 'date_from', 'date_to', 'amount_from', 'amount_to']);
        $query = $this->query->builder($user, $filters);

        $sortBy = (string) $request->input('sort_by', 'id');
        $sortOrder = (string) $request->input('sort_order', 'desc');
        $this->query->applySort($query, $sortBy, $sortOrder);

        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        return [$query, [
            'search' => trim((string) $request->input('search', '')),
            'selected_statuses' => $this->query->list($request->input('status')),
            'reasons' => $this->query->list($request->input('reason')),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'amount_from' => $request->input('amount_from'),
            'amount_to' => $request->input('amount_to'),
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'per_page' => $perPage,
        ]];
    }

    /**
     * Форма создания возврата.
     */
    public function create(): InertiaResponse
    {
        return Inertia::render('User/Cabinet/Returns/Create', [
            'reasons' => $this->reasonOptions(),
        ]);
    }

    /**
     * Сохранение нового возврата.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.shipment_item_id' => 'required|integer|exists:shipment_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|in:'.implode(',', array_column(ReturnReason::cases(), 'value')),
            'items.*.reason_comment' => 'nullable|string',
        ], [
            'items.required' => 'Добавьте хотя бы одну позицию возврата.',
            'items.min' => 'Добавьте хотя бы одну позицию возврата.',
            'items.*.shipment_item_id.required' => 'Выберите позицию реализации.',
            'items.*.shipment_item_id.exists' => 'Выбранная позиция реализации не найдена.',
            'items.*.quantity.required' => 'Укажите количество.',
            'items.*.quantity.min' => 'Количество должно быть не менее 1.',
            'items.*.reason.required' => 'Укажите причину возврата.',
            'items.*.reason.in' => 'Недопустимая причина возврата.',
        ]);

        try {
            $return = $this->returnService->createForUser($user, $validated);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e->getMessage() ?: 'Ошибка при создании возврата.');
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Ошибка при создании возврата: '.$e->getMessage());
        }

        return redirect()
            ->route('cabinet.returns.show', $return)
            ->with('success', 'Заявка на возврат успешно создана.');
    }

    /**
     * Просмотр возврата.
     */
    public function show(Request $request, ProductReturn $return): InertiaResponse
    {
        abort_unless($return->user_id === $request->user()->id, 403);

        return Inertia::render('User/Cabinet/Returns/Show', [
            'return' => $this->presenter->card($return),
            'statuses' => $this->statusOptions(),
        ]);
    }

    /**
     * Автокомплит реализаций текущего пользователя (C-3.1 … C-3.4).
     */
    public function searchShipments(Request $request): JsonResponse
    {
        return response()->json(
            $this->bases->searchShipments($request->user(), (string) $request->input('query'))
        );
    }

    /**
     * Позиции выбранной реализации с доступным к возврату количеством.
     */
    public function getShipmentItems(Request $request): JsonResponse
    {
        $shipment = $this->bases->shipmentFor($request->user(), (int) $request->input('shipment_id'));

        return response()->json($this->bases->forShipment($shipment));
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{value: string, label: string}>
     */
    private function statusOptions(): \Illuminate\Support\Collection
    {
        return collect(ReturnStatus::cases())->map(fn ($case) => [
            'value' => $case->value,
            'label' => $this->presenter->statusLabel($case),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{value: string, label: string}>
     */
    private function reasonOptions(): \Illuminate\Support\Collection
    {
        return collect(ReturnReason::cases())->map(fn ($case) => [
            'value' => $case->value,
            'label' => $this->presenter->reasonLabel($case),
        ]);
    }
}
