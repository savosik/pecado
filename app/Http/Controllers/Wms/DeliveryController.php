<?php

namespace App\Http\Controllers\Wms;

use App\Enums\Delivery\DeliveryShipmentStatus;
use App\Http\Requests\Wms\StoreDeliveryShipmentRequest;
use App\Http\Requests\Wms\UpdateDeliveryShipmentRequest;
use App\Models\Delivery\ApiShipRequest;
use App\Models\Delivery\DeliveryShipment;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Delivery\ApiShip\ApiShipClient;
use App\Services\Delivery\ApiShipSettings;
use App\Services\Delivery\AvailableShipmentsPresenter;
use App\Services\Delivery\DeliveryAddressResolver;
use App\Services\Delivery\DeliveryCandidateQuery;
use App\Services\Delivery\DeliveryOrderSubmitter;
use App\Services\Delivery\DeliveryRateCalculator;
use App\Services\Delivery\DeliveryShipmentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Отправки груза транспортными компаниями через ApiShip.
 *
 * Мастер разложен на две страницы намеренно. Состав, места и адрес задаются
 * в Create и сохраняются черновиком; расчёт тарифов и передача заявки живут
 * в карточке — они опираются на сохранённые вес и адрес, и считать их по
 * несохранённой форме означало бы держать половину состояния в браузере.
 */
class DeliveryController extends WmsController
{
    /**
     * Поля, по которым разрешена сортировка. Белый список: значение уходит в orderBy как есть.
     *
     * @var list<string>
     */
    private const SORTS = ['number', 'created_at', 'status', 'delivery_cost', 'submitted_at'];

    public function __construct(
        private readonly DeliveryShipmentBuilder $builder,
        private readonly DeliveryRateCalculator $rates,
        private readonly DeliveryOrderSubmitter $submitter,
        private readonly DeliveryAddressResolver $addresses,
        private readonly ApiShipClient $client,
        private readonly ApiShipSettings $settings,
        private readonly DeliveryCandidateQuery $candidates,
    ) {}

    public function index(Request $request): Response
    {
        [$sortBy, $sortOrder] = $this->sort($request);
        $perPage = min(max((int) $request->input('per_page', 30), 10), 100);

        $deliveries = $this->filteredQuery($request)
            ->with(['user:id,name,erp_name', 'company:id,name'])
            ->withCount('shipments')
            ->orderBy($sortBy, $sortOrder)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through($this->presentRow(...));

        return Inertia::render('Wms/Pages/Deliveries/Index', [
            'deliveries' => $deliveries,
            'filters' => $this->filterState($request),
            'stats' => $this->stats($request),
            'options' => [
                'statuses' => $this->statusOptions(),
            ],
            'sort' => ['by' => $sortBy, 'order' => $sortOrder],
            'perPage' => $perPage,
            'integrationEnabled' => $this->client->enabled(),
        ]);
    }

    /**
     * Мастер: шаг выбора реализаций, мест и адреса.
     */
    public function create(Request $request, AvailableShipmentsPresenter $presenter): Response
    {
        // Реализации приходят предвыбранными из раздела «Реализации к доставке» —
        // мастеру остаётся собрать места и адрес.
        $preselectedIds = $this->arrayInput($request, 'shipment_ids');

        $preselected = $preselectedIds === [] ? [] : $presenter->flat(
            $this->candidates
                ->withRelations($this->candidates->build(['shipment_ids' => $preselectedIds]))
                ->orderByDesc('date')
                ->get(),
        );

        return Inertia::render('Wms/Pages/Deliveries/Create', [
            'preselected' => $preselected,
            'defaults' => [
                'place' => [
                    'length' => $this->settings->int('default_place_length', 40),
                    'width' => $this->settings->int('default_place_width', 30),
                    'height' => $this->settings->int('default_place_height', 20),
                ],
                'deliveryType' => DeliveryShipment::DELIVERY_TYPE_DOOR,
                'pickupType' => DeliveryShipment::PICKUP_TYPE_COURIER,
            ],
            'integrationEnabled' => $this->client->enabled(),
        ]);
    }

    /**
     * Компактный поиск реализаций для мультивыбора в форме создания.
     *
     * Разбор «что вообще надо везти» живёт в разделе «Реализации к доставке»:
     * там фильтры, группировки и скрытие. Форме создания нужен плоский список
     * с достаточным минимумом подсказок, чтобы не перепутать документ.
     */
    public function searchShipments(Request $request, AvailableShipmentsPresenter $presenter): JsonResponse
    {
        $shipments = $this->candidates
            ->withRelations($this->candidates->build([
                'search' => $request->input('search'),
                'shipment_ids' => $this->arrayInput($request, 'shipment_ids'),
                'user_id' => $request->integer('user_id') ?: null,
            ], $request->integer('except_delivery_id') ?: null))
            ->orderByDesc('date')
            ->limit(min(max((int) $request->input('limit', 40), 5), 100))
            ->get();

        return response()->json([
            'shipments' => $presenter->flat($shipments),
        ]);
    }

    /**
     * Варианты адреса получателя для выбранного клиента.
     *
     * Три источника, потому что данные о доставке у нас исторически размазаны:
     * адрес в заказе — свободный текст, справочник клиента — иногда с разобранным
     * ответом DaData, а ручной ввод — единственный способ, когда груз едет
     * не туда, куда обычно.
     */
    public function recipientOptions(Request $request): JsonResponse
    {
        $userId = $request->integer('user_id');

        if (! $userId) {
            return response()->json(['contact' => null, 'order_addresses' => [], 'client_addresses' => []]);
        }

        $user = User::query()->find($userId);

        $orderAddresses = Order::query()
            ->where('user_id', $userId)
            ->whereNotNull('delivery_address')
            ->where('delivery_address', '!=', '')
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('delivery_address')
            ->map(static fn (string $address): string => trim($address))
            ->unique()
            ->values()
            ->map(static fn (string $address, int $index): array => [
                'id' => 'order-'.$index,
                'label' => $address,
                'address_string' => $address,
                'data' => null,
            ]);

        $clientAddresses = DeliveryAddress::query()
            ->withoutGlobalScopes()
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->limit(20)
            ->get()
            ->map(static fn (DeliveryAddress $address): array => [
                'id' => 'client-'.$address->getKey(),
                'label' => $address->name ? "{$address->name}: {$address->address}" : $address->address,
                'address_string' => $address->address,
                'data' => $address->address_data,
            ]);

        return response()->json([
            'contact' => $user === null ? null : [
                'contactName' => $user->erp_name ?: $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
            ],
            'order_addresses' => $orderAddresses->values(),
            'client_addresses' => $clientAddresses->values(),
        ]);
    }

    /**
     * Разобрать выбранный адрес на компоненты (регион, город, улица, дом).
     *
     * Отдельный эндпоинт, потому что адрес из заказа и из справочника — свободный
     * текст: без разбора ApiShip его не примет, а разбирать в браузере нечем.
     */
    public function resolveAddress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_string' => ['required', 'string', 'max:500'],
            'data' => ['nullable', 'array'],
        ], [
            'address_string.required' => 'Укажите адрес.',
        ]);

        $result = isset($validated['data'])
            ? $this->addresses->fromStoredAddress($validated['data'], $validated['address_string'])
            : $this->addresses->fromString($validated['address_string']);

        return response()->json($result);
    }

    public function store(StoreDeliveryShipmentRequest $request): RedirectResponse
    {
        try {
            $delivery = $this->builder->create(
                $this->payload($request->validated()),
                $this->wmsActor($request),
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.deliveries.show', $delivery)
            ->with('success', "Отправка {$delivery->number} создана. Рассчитайте доставку и передайте заявку в ТК.");
    }

    public function show(Request $request, DeliveryShipment $delivery): Response
    {
        $delivery->load([
            'user:id,name,erp_name,email',
            'company:id,name',
            'warehouse:id,name',
            'creator:id,name',
            'submitter:id,name',
            'places',
            'statusHistories',
            'shipments.items',
        ]);

        return Inertia::render('Wms/Pages/Deliveries/Show', [
            'delivery' => $this->presentCard($delivery),
            // Журнал вызовов — инструмент разбора полётов, кладовщику он только мешает.
            'apiLog' => $this->isWarehouseHead($request) ? $this->presentApiLog($delivery) : null,
            'integrationEnabled' => $this->client->enabled(),
        ]);
    }

    public function update(UpdateDeliveryShipmentRequest $request, DeliveryShipment $delivery): RedirectResponse
    {
        if (! $delivery->status->isEditable()) {
            return back()->with('error', 'Отправку уже передали перевозчику — её состав больше не редактируется.');
        }

        try {
            $this->builder->update($delivery, $this->payload($request->validated()));
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Отправка обновлена.');
    }

    /**
     * Расчёт тарифов по сохранённому черновику.
     */
    public function calculate(Request $request, DeliveryShipment $delivery): JsonResponse
    {
        $result = $this->rates->forShipment($delivery, $this->wmsActor($request)->id);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Пункты выдачи выбранного перевозчика в городе получателя.
     */
    public function points(Request $request, DeliveryShipment $delivery): JsonResponse
    {
        $city = $request->input('city') ?: $delivery->recipient_city;
        $providerKey = $request->input('provider_key') ?: $delivery->provider_key;

        if (! $city || ! $providerKey) {
            return response()->json([
                'points' => [],
                'error' => 'Сначала выберите перевозчика и укажите город получателя.',
            ], 422);
        }

        $result = $this->client->getPoints([
            'limit' => 200,
            'offset' => 0,
            'filter' => "city={$city};providerKey={$providerKey}",
        ]);

        if (! $result->ok) {
            return response()->json(['points' => [], 'error' => $result->error], 422);
        }

        $rows = $result->data()['rows'] ?? $result->data()['points'] ?? [];

        return response()->json([
            'points' => collect(is_array($rows) ? $rows : [])
                ->map(static fn (array $point): array => [
                    'id' => (string) ($point['id'] ?? $point['providerKey'] ?? ''),
                    'name' => (string) ($point['name'] ?? 'Пункт выдачи'),
                    'address' => (string) ($point['address'] ?? ''),
                    'phone' => $point['phone'] ?? null,
                    'timetable' => $point['timetable'] ?? null,
                ])
                ->filter(static fn (array $point): bool => $point['id'] !== '')
                ->values(),
            'error' => null,
        ]);
    }

    /**
     * Передать заявку перевозчику.
     */
    public function submit(Request $request, DeliveryShipment $delivery): RedirectResponse
    {
        $result = $this->submitter->submit($delivery, $this->wmsActor($request)->id);

        if (! $result['ok']) {
            return back()->with('error', 'Заявка не создана: '.$result['error']);
        }

        return back()->with('success', 'Заявка передана перевозчику. Трек-номер появится, как только ТК его выдаст.');
    }

    public function cancel(Request $request, DeliveryShipment $delivery): RedirectResponse
    {
        $result = $this->submitter->cancel($delivery, $this->wmsActor($request)->id);

        if (! $result['ok']) {
            return back()->with('error', 'Отменить заявку не удалось: '.$result['error']);
        }

        return back()->with('success', 'Заявка отменена.');
    }

    /**
     * Этикетка (ярлык) от перевозчика. ApiShip отдаёт ссылку на PDF в своём хранилище.
     */
    public function label(Request $request, DeliveryShipment $delivery): RedirectResponse
    {
        if (! $delivery->apiship_order_id) {
            return back()->with('error', 'Этикетка появится после передачи заявки перевозчику.');
        }

        $result = $this->client->getLabels(
            [$delivery->apiship_order_id],
            $delivery->id,
            $this->wmsActor($request)->id,
        );

        $url = $result->data()['url'] ?? null;

        if (! $result->ok || ! is_string($url) || $url === '') {
            return back()->with('error', 'Этикетку получить не удалось: '.($result->error ?? 'перевозчик не вернул файл'));
        }

        return redirect()->away($url);
    }

    /**
     * Вызов курьера за грузом.
     */
    public function courier(Request $request, DeliveryShipment $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time_start' => ['required', 'date_format:H:i'],
            'time_end' => ['required', 'date_format:H:i', 'after:time_start'],
        ], [
            'date.required' => 'Укажите дату приезда курьера.',
            'date.after_or_equal' => 'Дата приезда курьера не может быть в прошлом.',
            'time_start.required' => 'Укажите начало интервала.',
            'time_start.date_format' => 'Время указывается в формате ЧЧ:ММ.',
            'time_end.required' => 'Укажите конец интервала.',
            'time_end.date_format' => 'Время указывается в формате ЧЧ:ММ.',
            'time_end.after' => 'Конец интервала должен быть позже начала.',
        ]);

        if (! $delivery->apiship_order_id) {
            return back()->with('error', 'Курьера можно вызвать только после передачи заявки перевозчику.');
        }

        $sender = $this->addresses->sender();
        $first = $delivery->places()->orderBy('number')->first();

        $result = $this->client->callCourier(array_filter([
            'providerKey' => $delivery->provider_key,
            'date' => $validated['date'],
            'timeStart' => $validated['time_start'],
            'timeEnd' => $validated['time_end'],
            'weight' => $delivery->effective_weight,
            'length' => (int) ($first?->length ?: $this->settings->int('default_place_length', 40)),
            'width' => (int) ($first?->width ?: $this->settings->int('default_place_width', 30)),
            'height' => (int) ($first?->height ?: $this->settings->int('default_place_height', 20)),
            'orderIds' => [(int) $delivery->apiship_order_id],
            'countryCode' => $sender['countryCode'] ?? 'RU',
            'region' => $sender['region'] ?? null,
            'city' => $sender['city'] ?? null,
            'street' => $sender['street'] ?? null,
            'house' => $sender['house'] ?? null,
            'postIndex' => $sender['index'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''), $delivery->id, $this->wmsActor($request)->id);

        if (! $result->ok) {
            return back()->with('error', 'Вызвать курьера не удалось: '.$result->error);
        }

        return back()->with('success', 'Заявка на вызов курьера принята.');
    }

    /**
     * Удалить черновик. Переданную заявку удалять нельзя — её сначала отменяют в ТК.
     */
    public function destroy(Request $request, DeliveryShipment $delivery): RedirectResponse
    {
        if ($delivery->apiship_order_id) {
            return back()->with('error', 'Сначала отмените заявку у перевозчика.');
        }

        $delivery->delete();

        return redirect()
            ->route('wms.deliveries.index')
            ->with('success', 'Черновик отправки удалён.');
    }

    // ─────────────────────────── Внутренности ───────────────────────────

    /**
     * Дозаполнить адрес получателя: фронт мог прислать только строку.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        $recipient = (array) ($validated['recipient'] ?? []);

        // Город есть всегда (правило required), а вот регион и индекс фронт мог
        // не передать — например, адрес пришёл из заказа одной строкой.
        if (($recipient['region'] ?? null) === null && ($recipient['addressString'] ?? null)) {
            $resolved = $this->addresses->fromString((string) $recipient['addressString']);
            $recipient = array_merge($resolved['address'], array_filter(
                $recipient,
                static fn ($value): bool => $value !== null && $value !== '',
            ));
        }

        $validated['recipient'] = $recipient;

        return $validated;
    }

    /**
     * @return Builder<DeliveryShipment>
     */
    private function filteredQuery(Request $request, bool $withStatus = true): Builder
    {
        $query = DeliveryShipment::query();

        if ($search = trim((string) $request->input('search'))) {
            $like = "%{$search}%";
            $query->where(fn (Builder $q) => $q
                ->where('number', 'like', $like)
                ->orWhere('provider_number', 'like', $like)
                ->orWhere('recipient_contact', 'like', $like)
                ->orWhere('recipient_city', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u
                    ->where('name', 'like', $like)
                    ->orWhere('erp_name', 'like', $like)));
        }

        if ($withStatus && $statuses = $this->arrayInput($request, 'statuses')) {
            $query->whereIn('status', $statuses);
        }

        if ($providerKeys = $this->arrayInput($request, 'provider_keys')) {
            $query->whereIn('provider_key', $providerKeys);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * Счётчики по статусам и стоимость доставки за месяц.
     *
     * Считаются по запросу БЕЗ фильтра статуса — плитки остаются навигацией,
     * а не превращаются в «выбранный статус и семь нулей».
     *
     * @return array<string, mixed>
     */
    private function stats(Request $request): array
    {
        $base = $this->filteredQuery($request, withStatus: false);

        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'by_status' => collect(DeliveryShipmentStatus::cases())
                ->map(static fn (DeliveryShipmentStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'color' => $status->color(),
                    'count' => (int) ($byStatus[$status->value] ?? 0),
                ])
                ->all(),
            'total' => (int) $byStatus->sum(),
            // Деньги считаем по уехавшим отправкам: черновик ничего не стоит,
            // отменённую заявку перевозчик не выставляет. Ручные отправки считаются
            // наравне с остальными — груз уехал, счёт будет.
            'cost_this_month' => round((float) DeliveryShipment::query()
                ->whereNotNull('submitted_at')
                ->whereNot('status', DeliveryShipmentStatus::CANCELLED)
                ->where('submitted_at', '>=', now()->startOfMonth())
                ->sum('delivery_cost'), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterState(Request $request): array
    {
        return [
            'search' => (string) $request->input('search', ''),
            'statuses' => $this->arrayInput($request, 'statuses'),
            'provider_keys' => $this->arrayInput($request, 'provider_keys'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sort(Request $request): array
    {
        $sortBy = (string) $request->input('sort', 'created_at');
        $sortOrder = strtolower((string) $request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sortBy, self::SORTS, true)) {
            $sortBy = 'created_at';
        }

        return [$sortBy, $sortOrder];
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

    /**
     * @return list<array{value: string, label: string, color: string}>
     */
    private function statusOptions(): array
    {
        return collect(DeliveryShipmentStatus::cases())
            ->map(static fn (DeliveryShipmentStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRow(DeliveryShipment $delivery): array
    {
        return [
            'id' => (int) $delivery->getKey(),
            'number' => $delivery->number,
            'status' => $delivery->status->value,
            'status_label' => $delivery->status->label(),
            'status_color' => $delivery->status->color(),
            'provider_key' => $delivery->carrier_label,
            'is_manual' => $delivery->is_manual,
            'tariff_name' => $delivery->tariff_name,
            'provider_number' => $delivery->provider_number,
            'tracking_url' => $delivery->tracking_url,
            'apiship_status_label' => $delivery->provider_status_label,
            'client' => $delivery->user?->erp_name ?: $delivery->user?->name ?: $delivery->company?->name,
            'recipient_city' => $delivery->recipient_city,
            'documents_count' => (int) ($delivery->shipments_count ?? 0),
            'places_count' => (int) $delivery->places_count,
            'weight' => $delivery->effective_weight,
            'delivery_cost' => $delivery->delivery_cost !== null ? (float) $delivery->delivery_cost : null,
            'created_label' => $delivery->created_at?->format('d.m.Y H:i'),
            'submitted_label' => $delivery->submitted_at?->format('d.m.Y H:i'),
            'url' => route('wms.deliveries.show', $delivery),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCard(DeliveryShipment $delivery): array
    {
        return [
            'id' => (int) $delivery->getKey(),
            'number' => $delivery->number,
            'status' => $delivery->status->value,
            'status_label' => $delivery->status->label(),
            'status_color' => $delivery->status->color(),
            'is_editable' => $delivery->status->isEditable(),
            'is_manual' => $delivery->is_manual,
            'provider_key' => $delivery->carrier_label,
            'tariff_id' => $delivery->tariff_id,
            'tariff_name' => $delivery->tariff_name,
            'delivery_type' => (int) $delivery->delivery_type,
            'pickup_type' => (int) $delivery->pickup_type,
            'point_id' => $delivery->point_id,
            'point_address' => $delivery->point_address,
            'apiship_order_id' => $delivery->apiship_order_id,
            'provider_number' => $delivery->provider_number,
            'barcode' => $delivery->barcode,
            'tracking_url' => $delivery->tracking_url,
            'apiship_status_key' => $delivery->apiship_status_key,
            'apiship_status_label' => $delivery->provider_status_label,
            'apiship_status_color' => $delivery->apiShipStatus()?->color(),
            'apiship_status_at' => $delivery->apiship_status_at?->format('d.m.Y H:i'),
            'calculated_weight' => (int) $delivery->calculated_weight,
            'declared_weight' => $delivery->declared_weight !== null ? (int) $delivery->declared_weight : null,
            'effective_weight' => $delivery->effective_weight,
            'assessed_cost' => (float) $delivery->assessed_cost,
            'delivery_cost' => $delivery->delivery_cost !== null ? (float) $delivery->delivery_cost : null,
            'pickup_date' => $delivery->pickup_date?->format('Y-m-d'),
            'comment' => $delivery->comment,
            'last_error' => $delivery->last_error,
            'client' => $delivery->user?->erp_name ?: $delivery->user?->name ?: $delivery->company?->name,
            'warehouse' => $delivery->warehouse?->name,
            'recipient' => $delivery->recipient,
            'created_by' => $delivery->creator?->name,
            'submitted_by' => $delivery->submitter?->name,
            'created_label' => $delivery->created_at?->format('d.m.Y H:i'),
            'submitted_label' => $delivery->submitted_at?->format('d.m.Y H:i'),
            'places' => $delivery->places->map(static fn ($place): array => [
                'number' => (int) $place->number,
                'weight' => (int) $place->weight,
                'length' => $place->length,
                'width' => $place->width,
                'height' => $place->height,
                'volumetric_weight' => $place->volumetricWeight(),
                'barcode' => $place->barcode,
            ])->values(),
            'documents' => $delivery->shipments->map(static fn (Shipment $shipment): array => [
                'id' => (int) $shipment->getKey(),
                'number' => $shipment->erp_number ?: $shipment->number ?: (string) $shipment->getKey(),
                'date_label' => $shipment->date?->format('d.m.Y'),
                'amount' => (float) $shipment->total_amount,
                'items_count' => $shipment->items->count(),
                'weight' => (int) ($shipment->pivot->weight ?? 0),
            ])->values(),
            'history' => $delivery->statusHistories->map(static fn ($row): array => [
                'id' => (int) $row->getKey(),
                'label' => $row->label,
                'status_key' => $row->to_status_key,
                'provider_code' => $row->provider_code,
                'source' => $row->source,
                'occurred_label' => $row->occurred_at->format('d.m.Y H:i'),
            ])->values(),
            'urls' => [
                'update' => route('wms.deliveries.update', $delivery),
                'calculate' => route('wms.deliveries.calculate', $delivery),
                'points' => route('wms.deliveries.points', $delivery),
                'submit' => route('wms.deliveries.submit', $delivery),
                'cancel' => route('wms.deliveries.cancel', $delivery),
                'label' => route('wms.deliveries.label', $delivery),
                'courier' => route('wms.deliveries.courier', $delivery),
                'destroy' => route('wms.deliveries.destroy', $delivery),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentApiLog(DeliveryShipment $delivery): array
    {
        return $delivery->apiShipRequests()
            ->limit(50)
            ->get()
            ->map(static fn (ApiShipRequest $row): array => [
                'id' => (int) $row->getKey(),
                'operation' => $row->operation,
                'endpoint' => $row->method.' '.$row->endpoint,
                'http_status' => $row->http_status,
                'duration_ms' => $row->duration_ms,
                'error' => $row->error_message,
                'is_successful' => $row->isSuccessful(),
                'created_label' => $row->created_at?->format('d.m.Y H:i:s'),
                'request' => $row->request_payload,
                'response' => $row->response_payload,
            ])
            ->all();
    }
}
