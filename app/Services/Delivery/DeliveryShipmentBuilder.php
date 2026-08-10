<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\DeliveryShipmentStatus;
use App\Models\Delivery\DeliveryShipment;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сборка отправки из реализаций 1С.
 *
 * Здесь же живёт единственное содержательное ограничение состава: одна реализация
 * не может лежать в двух ОДНОВРЕМЕННО активных отправках. Индексом это не выразить —
 * после отмены заявки груз собирают заново, и та же реализация обязана попасть
 * в новую отправку.
 */
class DeliveryShipmentBuilder
{
    public function __construct(private readonly DeliveryWeightCalculator $weights) {}

    /**
     * Создать черновик отправки.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \RuntimeException если реализация уже занята другой отправкой
     */
    public function create(array $data, User $actor): DeliveryShipment
    {
        /** @var list<int> $shipmentIds */
        $shipmentIds = array_values(array_unique(array_map('intval', (array) ($data['shipment_ids'] ?? []))));

        return DB::transaction(function () use ($data, $actor, $shipmentIds): DeliveryShipment {
            $shipments = $this->lockShipments($shipmentIds);
            $first = $shipments->first();

            $delivery = new DeliveryShipment([
                'user_id' => $first?->user_id,
                'company_id' => $first?->company_id,
                'warehouse_id' => $first?->warehouse_id,
                'status' => DeliveryShipmentStatus::DRAFT,
                'delivery_type' => (int) ($data['delivery_type'] ?? DeliveryShipment::DELIVERY_TYPE_DOOR),
                'pickup_type' => (int) ($data['pickup_type'] ?? DeliveryShipment::PICKUP_TYPE_COURIER),
                'created_by' => $actor->id,
            ]);
            $delivery->save();

            $this->applyDocuments($delivery, $shipments);
            $this->applyPayload($delivery, $data);

            return $delivery->fresh(['shipments', 'places']) ?? $delivery;
        });
    }

    /**
     * Обновить черновик: состав, места, адрес, выбранный тариф.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(DeliveryShipment $delivery, array $data): DeliveryShipment
    {
        return DB::transaction(function () use ($delivery, $data): DeliveryShipment {
            if (array_key_exists('shipment_ids', $data)) {
                $shipmentIds = array_values(array_unique(array_map('intval', (array) $data['shipment_ids'])));
                $shipments = $this->lockShipments($shipmentIds, $delivery->id);

                $first = $shipments->first();
                $delivery->fill([
                    'user_id' => $first?->user_id,
                    'company_id' => $first?->company_id,
                    'warehouse_id' => $first?->warehouse_id,
                ]);

                $this->applyDocuments($delivery, $shipments);
            }

            $this->applyPayload($delivery, $data);

            return $delivery->fresh(['shipments', 'places']) ?? $delivery;
        });
    }

    /**
     * Идентификаторы реализаций, уже занятых активными отправками.
     *
     * @param  int|null  $exceptDeliveryId  отправка, которую редактируем — её состав не считается занятым
     * @return list<int>
     */
    public function lockedShipmentIds(?int $exceptDeliveryId = null): array
    {
        $heldStatuses = array_values(array_map(
            static fn (DeliveryShipmentStatus $status): string => $status->value,
            array_filter(
                DeliveryShipmentStatus::cases(),
                static fn (DeliveryShipmentStatus $status): bool => $status->holdsDocuments(),
            ),
        ));

        return DB::table('delivery_shipment_documents')
            ->join('delivery_shipments', 'delivery_shipments.id', '=', 'delivery_shipment_documents.delivery_shipment_id')
            ->whereNull('delivery_shipments.deleted_at')
            ->whereIn('delivery_shipments.status', $heldStatuses)
            ->when($exceptDeliveryId !== null, fn ($query) => $query->where('delivery_shipments.id', '!=', $exceptDeliveryId))
            ->pluck('delivery_shipment_documents.shipment_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Загрузить реализации и убедиться, что они свободны.
     *
     * @param  list<int>  $shipmentIds
     * @return Collection<int, Shipment>
     *
     * @throws \RuntimeException
     */
    private function lockShipments(array $shipmentIds, ?int $exceptDeliveryId = null): Collection
    {
        if ($shipmentIds === []) {
            throw new \RuntimeException('Выберите хотя бы одну реализацию.');
        }

        /** @var Collection<int, Shipment> $shipments */
        $shipments = Shipment::query()
            ->with('items.product')
            ->whereIn('id', $shipmentIds)
            ->get();

        if ($shipments->count() !== count($shipmentIds)) {
            throw new \RuntimeException('Часть выбранных реализаций не найдена.');
        }

        // Разные клиенты в одной отправке — это разные адреса получателя,
        // одной заявкой такое не отправить.
        if ($shipments->pluck('user_id')->unique()->count() > 1) {
            throw new \RuntimeException('В одну отправку можно включить реализации только одного клиента.');
        }

        $locked = array_intersect($shipmentIds, $this->lockedShipmentIds($exceptDeliveryId));

        if ($locked !== []) {
            $numbers = $shipments->whereIn('id', $locked)
                ->map(static fn (Shipment $shipment): string => (string) ($shipment->erp_number ?: $shipment->number ?: $shipment->id))
                ->implode(', ');

            throw new \RuntimeException("Реализации уже включены в другую отправку: {$numbers}.");
        }

        return $shipments;
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     */
    private function applyDocuments(DeliveryShipment $delivery, Collection $shipments): void
    {
        $pivot = [];
        $totalWeight = 0;
        $totalAmount = 0.0;

        foreach ($shipments as $shipment) {
            $weight = $this->weights->forShipment($shipment);
            $totalWeight += $weight;
            $totalAmount += (float) $shipment->total_amount;

            $pivot[$shipment->id] = [
                'amount' => (float) $shipment->total_amount,
                'weight' => $weight,
            ];
        }

        $delivery->shipments()->sync($pivot);
        $delivery->fill([
            'calculated_weight' => $totalWeight,
            'assessed_cost' => round($totalAmount, 2),
        ]);
        $delivery->save();
    }

    /**
     * Применить всё, что не относится к составу: адрес, места, тариф, даты.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyPayload(DeliveryShipment $delivery, array $data): void
    {
        $fields = [
            'delivery_type', 'pickup_type', 'point_id', 'point_address',
            'provider_key', 'tariff_id', 'tariff_name',
            'delivery_cost', 'delivery_cost_original',
            'pickup_date', 'comment',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $delivery->{$field} = $data[$field];
            }
        }

        if (array_key_exists('recipient', $data) && is_array($data['recipient'])) {
            $recipient = array_filter(
                $data['recipient'],
                static fn ($value): bool => $value !== null && $value !== '',
            );

            $delivery->recipient = $recipient;
            $delivery->recipient_city = $recipient['city'] ?? null;
            $delivery->recipient_contact = $recipient['contactName'] ?? $recipient['companyName'] ?? null;
        }

        if (array_key_exists('places', $data) && is_array($data['places'])) {
            $this->syncPlaces($delivery, $data['places']);
        }

        // Выбранный тариф переводит черновик в «посчитано», но только если груз
        // ещё не уехал: правка карточки в пути статус сбрасывать не должна.
        if ($delivery->status === DeliveryShipmentStatus::DRAFT && $delivery->tariff_id) {
            $delivery->status = DeliveryShipmentStatus::CALCULATED;
        }

        $delivery->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $places
     */
    private function syncPlaces(DeliveryShipment $delivery, array $places): void
    {
        $delivery->places()->delete();

        $number = 0;
        $totalWeight = 0;

        foreach ($places as $place) {
            $number++;
            $weight = max(0, (int) ($place['weight'] ?? 0));
            $totalWeight += $weight;

            $delivery->places()->create([
                'number' => $number,
                'weight' => $weight,
                'length' => $this->dimension($place, 'length'),
                'width' => $this->dimension($place, 'width'),
                'height' => $this->dimension($place, 'height'),
            ]);
        }

        $delivery->fill([
            'places_count' => $number,
            // Заявленный вес — сумма мест: именно её видел кладовщик на весах.
            // Ноль означает «места есть, но не взвешены» — тогда едем от расчётного.
            'declared_weight' => $totalWeight > 0 ? $totalWeight : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function dimension(array $place, string $key): ?int
    {
        $value = $place[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
