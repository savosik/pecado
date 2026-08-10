<?php

namespace App\Http\Controllers\Wms;

use App\Models\Delivery\HiddenShipment;
use App\Services\Delivery\AvailableShipmentsPresenter;
use App\Services\Delivery\DeliveryCandidateQuery;
use App\Services\Delivery\ManualDeliveryRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Раздел «Реализации к доставке» — рабочий стол склада перед созданием отправки.
 *
 * Отдельная страница, а не шаг мастера: разбор «что вообще надо везти» — это
 * самостоятельная работа с фильтрами, группировками и поиском, и держать её
 * внутри формы создания означало бы делать форму неподъёмной.
 *
 * Отсюда выбранные реализации уходят в мастер уже отобранными.
 */
class DeliveryCandidateController extends WmsController
{
    /**
     * Потолок выборки. Группировка делает длинный список читаемым, поэтому берём
     * шире плоского списка: 50 строк на семь тысяч реализаций — почти всегда
     * «моего клиента тут нет».
     */
    private const DEFAULT_LIMIT = 200;

    private const MAX_LIMIT = 500;

    public function __construct(
        private readonly DeliveryCandidateQuery $candidates,
        private readonly AvailableShipmentsPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->filterState($request);
        $limit = $this->limit($request);

        $query = $this->candidates->build($filters);
        $matched = (clone $query)->count();

        $shipments = $this->candidates->withRelations(clone $query)
            ->orderByDesc('date')
            ->limit($limit)
            ->get();

        $tabs = $this->presenter->present(
            $shipments,
            $filters['group_by'],
            $filters['row_sort'],
            [
                'address' => $filters['address'],
                'delivery_kinds' => $filters['delivery_kinds'],
                'only_without_goods_issue' => $filters['only_without_goods_issue'],
                'only_retry' => $filters['only_retry'],
            ],
        );

        return Inertia::render('Wms/Pages/DeliveryCandidates/Index', [
            'clients' => $tabs['delivery'],
            'pickupClients' => $tabs['pickup'],
            'filters' => $filters,
            'options' => array_merge($this->candidates->filterOptions(), [
                'manualStatuses' => [
                    ['value' => 'submitted', 'label' => 'Передана перевозчику'],
                    ['value' => 'in_transit', 'label' => 'В пути'],
                    ['value' => 'delivered', 'label' => 'Доставлена'],
                ],
            ]),
            'meta' => [
                'matched' => $matched,
                'loaded' => $shipments->count(),
                // Постфильтры (адрес, способ, наличие ордера) работают уже по
                // загруженным строкам — при упёршемся потолке об этом надо сказать.
                'capped' => $matched > $limit,
                'limit' => $limit,
                'hidden_count' => $this->candidates->hiddenCount(),
            ],
        ]);
    }

    /**
     * Убрать реализацию из списка кандидатов или вернуть обратно.
     */
    public function toggleHidden(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shipment_id' => ['required', 'integer', 'exists:shipments,id'],
            'hidden' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'shipment_id.required' => 'Не указана реализация.',
            'shipment_id.exists' => 'Такая реализация не найдена.',
            'reason.max' => 'Пометка слишком длинная (максимум 255 символов).',
        ]);

        if ($validated['hidden']) {
            HiddenShipment::updateOrCreate(
                ['shipment_id' => $validated['shipment_id']],
                [
                    'hidden_by' => $this->wmsActor($request)->id,
                    'reason' => $validated['reason'] ?? null,
                ],
            );
        } else {
            HiddenShipment::query()->where('shipment_id', $validated['shipment_id'])->delete();
        }

        return back(fallback: route('wms.delivery-candidates.index'));
    }

    /**
     * Отметить реализации отправленными без ApiShip.
     *
     * Нужно с первого дня: систему внедряют задним числом, часть груза уже уехала,
     * а часть перевозчиков к агрегатору вообще не подключена.
     */
    public function markShipped(Request $request, ManualDeliveryRecorder $recorder): RedirectResponse
    {
        $validated = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1', 'max:50'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
            'carrier_name' => ['required', 'string', 'max:150'],
            'provider_number' => ['nullable', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'url', 'max:500'],
            'delivery_cost' => ['nullable', 'numeric', 'min:0'],
            'shipped_at' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(ManualDeliveryRecorder::STATUSES)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'shipment_ids.required' => 'Выберите хотя бы одну реализацию.',
            'carrier_name.required' => 'Укажите, какой транспортной компанией уехал груз.',
            'tracking_url.url' => 'Ссылка отслеживания должна быть корректным адресом.',
            'shipped_at.required' => 'Укажите дату отправки.',
            'shipped_at.before_or_equal' => 'Дата отправки не может быть в будущем — это отметка о свершившемся факте.',
            'status.required' => 'Выберите состояние отправки.',
            'status.in' => 'Такое состояние вручную не выставляется.',
            'comment.max' => 'Комментарий слишком длинный (максимум 2000 символов).',
        ]);

        try {
            $delivery = $recorder->record(
                array_map('intval', $validated['shipment_ids']),
                $validated,
                $this->wmsActor($request),
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Отправка {$delivery->number} записана как отправленная вручную.");
    }

    /**
     * @return array<string, mixed>
     */
    private function filterState(Request $request): array
    {
        $groupBy = (string) $request->input('group_by', 'client');
        $rowSort = (string) $request->input('row_sort', 'date_desc');

        return [
            'search' => (string) $request->input('search', ''),
            'group_by' => in_array($groupBy, AvailableShipmentsPresenter::GROUP_BY, true) ? $groupBy : 'client',
            'row_sort' => in_array($rowSort, AvailableShipmentsPresenter::SORTS, true) ? $rowSort : 'date_desc',
            'client_ids' => $this->arrayInput($request, 'client_ids'),
            'warehouse_ids' => $this->arrayInput($request, 'warehouse_ids'),
            'order_statuses' => $this->arrayInput($request, 'order_statuses'),
            'goods_issue_statuses' => $this->arrayInput($request, 'goods_issue_statuses'),
            'delivery_kinds' => $this->arrayInput($request, 'delivery_kinds'),
            'address' => (string) $request->input('address', ''),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'amount_from' => $request->input('amount_from'),
            'amount_to' => $request->input('amount_to'),
            'only_without_goods_issue' => $request->boolean('only_without_goods_issue'),
            'only_retry' => $request->boolean('only_retry'),
            'show_hidden' => $request->boolean('show_hidden'),
        ];
    }

    private function limit(Request $request): int
    {
        return min(max((int) $request->input('limit', self::DEFAULT_LIMIT), 20), self::MAX_LIMIT);
    }

    /**
     * Принимает и `statuses[]=a&statuses[]=b`, и строку через запятую.
     *
     * @return list<string>
     */
    private function arrayInput(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
