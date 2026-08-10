<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\DeliveryShipmentStatus;
use App\Models\Delivery\DeliveryShipment;
use App\Models\Delivery\DeliveryShipmentStatusHistory;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Отметка «эти реализации уже отправлены» — без обращения к ApiShip.
 *
 * Систему внедряют задним числом: часть груза склад отправил до неё, а часть
 * перевозчиков к агрегатору вообще не подключена — заявку делают на сайте ТК
 * или по телефону. Без ручной отметки такие реализации вечно висели бы
 * в кандидатах, и списку нельзя было бы верить.
 *
 * Результат — обычная отправка с флагом `is_manual`: она так же занимает
 * реализации, несёт трек и статус. Разница в том, что заявки в ApiShip нет,
 * поэтому расчёт, этикетка и вызов курьера для неё недоступны.
 */
class ManualDeliveryRecorder
{
    /**
     * Статусы, которые склад может проставить руками.
     *
     * Ровно три: остальные приезжают от перевозчика, и давать их выбирать
     * означало бы позволить нарисовать состояние, которого не было.
     *
     * @var list<string>
     */
    public const STATUSES = [
        DeliveryShipmentStatus::SUBMITTED->value,
        DeliveryShipmentStatus::IN_TRANSIT->value,
        DeliveryShipmentStatus::DELIVERED->value,
    ];

    public function __construct(private readonly DeliveryWeightCalculator $weights) {}

    /**
     * @param  list<int>  $shipmentIds
     * @param  array<string, mixed>  $data
     *
     * @throws \RuntimeException
     */
    public function record(array $shipmentIds, array $data, User $actor): DeliveryShipment
    {
        $shipmentIds = array_values(array_unique(array_map('intval', $shipmentIds)));

        if ($shipmentIds === []) {
            throw new \RuntimeException('Выберите хотя бы одну реализацию.');
        }

        return DB::transaction(function () use ($shipmentIds, $data, $actor): DeliveryShipment {
            /** @var Collection<int, Shipment> $shipments */
            $shipments = Shipment::query()
                ->with('items.product')
                ->whereIn('id', $shipmentIds)
                ->get();

            if ($shipments->count() !== count($shipmentIds)) {
                throw new \RuntimeException('Часть выбранных реализаций не найдена.');
            }

            $this->assertFree($shipments, $shipmentIds);

            $status = DeliveryShipmentStatus::from(
                in_array($data['status'] ?? '', self::STATUSES, true)
                    ? $data['status']
                    : DeliveryShipmentStatus::SUBMITTED->value,
            );

            $submittedAt = isset($data['shipped_at'])
                ? Carbon::parse((string) $data['shipped_at'])
                : Carbon::now();

            $first = $shipments->first();

            $delivery = new DeliveryShipment([
                'user_id' => $first?->user_id,
                'company_id' => $first?->company_id,
                'warehouse_id' => $first?->warehouse_id,
                'status' => $status,
                'is_manual' => true,
                'carrier_name' => $data['carrier_name'] ?? null,
                'provider_number' => $data['provider_number'] ?? null,
                'tracking_url' => $data['tracking_url'] ?? null,
                'delivery_cost' => $data['delivery_cost'] ?? null,
                'comment' => $data['comment'] ?? null,
                'created_by' => $actor->id,
                'submitted_by' => $actor->id,
                'submitted_at' => $submittedAt,
                // Мест и габаритов у ручной отправки нет: груз уже уехал,
                // и восстанавливать коробки задним числом бессмысленно.
                'places_count' => 0,
            ]);
            $delivery->save();

            $this->attachDocuments($delivery, $shipments);

            $delivery->statusHistories()->create([
                'from_status_key' => null,
                'to_status_key' => 'manual',
                'status_name' => 'Отмечено вручную: '.$status->label(),
                'source' => DeliveryShipmentStatusHistory::SOURCE_MANUAL,
                'occurred_at' => $submittedAt,
            ]);

            return $delivery->fresh(['shipments']) ?? $delivery;
        });
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @param  list<int>  $shipmentIds
     *
     * @throws \RuntimeException
     */
    private function assertFree(Collection $shipments, array $shipmentIds): void
    {
        // Разные клиенты в одной отправке — разные адреса получателя. Для ручной
        // отметки это тоже верно: одна строка журнала описывает одну посылку.
        if ($shipments->pluck('user_id')->unique()->count() > 1) {
            throw new \RuntimeException('В одну отправку можно включить реализации только одного клиента.');
        }

        $heldStatuses = array_values(array_map(
            static fn (DeliveryShipmentStatus $status): string => $status->value,
            array_filter(
                DeliveryShipmentStatus::cases(),
                static fn (DeliveryShipmentStatus $status): bool => $status->holdsDocuments(),
            ),
        ));

        $locked = DB::table('delivery_shipment_documents')
            ->join('delivery_shipments', 'delivery_shipments.id', '=', 'delivery_shipment_documents.delivery_shipment_id')
            ->whereIn('delivery_shipment_documents.shipment_id', $shipmentIds)
            ->whereNull('delivery_shipments.deleted_at')
            ->whereIn('delivery_shipments.status', $heldStatuses)
            ->pluck('delivery_shipment_documents.shipment_id')
            ->unique();

        if ($locked->isNotEmpty()) {
            $numbers = $shipments
                ->whereIn('id', $locked->all())
                ->map(static fn (Shipment $shipment): string => (string) ($shipment->erp_number ?: $shipment->number ?: $shipment->id))
                ->implode(', ');

            throw new \RuntimeException("Реализации уже включены в другую отправку: {$numbers}.");
        }
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     */
    private function attachDocuments(DeliveryShipment $delivery, Collection $shipments): void
    {
        $pivot = [];
        $totalWeight = 0;
        $totalAmount = 0.0;

        foreach ($shipments as $shipment) {
            $weight = $this->weights->forShipment($shipment);
            $totalWeight += $weight;
            $totalAmount += (float) $shipment->total_amount;

            $pivot[$shipment->id] = ['amount' => (float) $shipment->total_amount, 'weight' => $weight];
        }

        $delivery->shipments()->sync($pivot);
        $delivery->forceFill([
            'calculated_weight' => $totalWeight,
            'assessed_cost' => round($totalAmount, 2),
        ])->save();
    }
}
