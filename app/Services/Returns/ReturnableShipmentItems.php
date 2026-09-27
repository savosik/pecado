<?php

namespace App\Services\Returns;

use App\Enums\ReturnStatus;
use App\Models\ReturnItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Support\Search\QueryRouter;
use Illuminate\Support\Collection;

/**
 * Основания возврата: какие реализации у клиента есть и что из них ещё можно вернуть.
 *
 * Общая часть формы возврата в кабинете и API v1. Реализации внутренних
 * организаций (образцы «Рекламы») не показываем — их не покупали, возвращать нечего.
 */
class ReturnableShipmentItems
{
    /**
     * Реализация клиента по id, доступная как основание возврата.
     */
    public function shipmentFor(User $user, int $shipmentId): Shipment
    {
        return Shipment::query()
            ->where('id', $shipmentId)
            ->where('user_id', $user->id)
            ->withoutInternalOrganizations()
            ->firstOrFail();
    }

    /**
     * Позиции реализации с уже возвращённым и доступным к возврату количеством.
     *
     * @return array{shipment: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function forShipment(Shipment $shipment): array
    {
        $items = ShipmentItem::with('product')
            ->where('shipment_id', $shipment->id)
            ->get()
            ->map(function (ShipmentItem $si) use ($shipment) {
                $already = (int) ReturnItem::where('shipment_item_id', $si->id)->sum('quantity');
                $available = max(0, (int) $si->quantity - $already);

                return [
                    'shipment_item_id' => $si->id,
                    'product' => $si->product ? [
                        'id' => $si->product->id,
                        'name' => $si->product->name,
                        'sku' => $si->product->sku,
                        'image_url' => $si->product->getFirstMediaUrl('main'),
                    ] : null,
                    'price' => (float) $si->price,
                    'currency_code' => $shipment->currency_code,
                    'shipped_quantity' => (int) $si->quantity,
                    'already_returned' => $already,
                    'available_quantity' => $available,
                ];
            })
            ->values()
            ->all();

        return [
            'shipment' => [
                'id' => $shipment->id,
                'uuid' => $shipment->uuid,
                'number' => $shipment->number,
                'date' => $shipment->date?->format('d.m.Y'),
                'currency_code' => $shipment->currency_code,
            ],
            'items' => $items,
        ];
    }

    /**
     * Реализации клиента по строке поиска: номер (в т. ч. без дефисов), товар,
     * бренд, штрихкод в составе. С числом открытых возвратов по каждой.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function searchShipments(User $user, string $q, int $limit = 20): Collection
    {
        $q = trim($q);

        $query = Shipment::query()
            ->select('shipments.*')
            ->where('user_id', $user->id)
            ->withoutInternalOrganizations()
            ->with(['items.product.brand'])
            ->withCount('items')
            ->selectSub(function ($sub) {
                $sub->from('return_items')
                    ->join('returns', 'returns.id', '=', 'return_items.return_id')
                    ->whereColumn('return_items.shipment_id', 'shipments.id')
                    ->whereNotIn('returns.status', [
                        ReturnStatus::COMPLETED->value,
                        ReturnStatus::REJECTED->value,
                    ])
                    ->selectRaw('COUNT(DISTINCT return_items.return_id)');
            }, 'open_returns_count')
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($q !== '') {
            $normalized = preg_replace('/[\s\-]+/u', '', $q);
            $queryType = QueryRouter::classify($q);

            $query->where(function ($sub) use ($q, $normalized, $queryType) {
                $sub->where('number', 'like', "%{$q}%")
                    ->orWhere('erp_number', 'like', "%{$q}%");

                $sub->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                $sub->orWhereRaw("REPLACE(REPLACE(COALESCE(erp_number, ''), '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);

                $sub->orWhereHas('items.product', function ($p) use ($q, $queryType) {
                    $p->where(function ($pp) use ($q, $queryType) {
                        $pp->where('name', 'like', "%{$q}%")
                            ->orWhere('sku', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%")
                            ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$q}%"));

                        if ($queryType === QueryRouter::TYPE_BARCODE) {
                            $pp->orWhereHas('barcodes', fn ($bc) => $bc->where('barcode', $q));
                        }
                    });
                });
            });
        }

        return $query->limit($limit)->get()->map(function (Shipment $s) use ($q) {
            $matchSource = 'number';
            $matchProduct = null;

            if ($q !== '') {
                $normalized = preg_replace('/[\s\-]+/u', '', $q);
                $needle = mb_strtolower($q);
                $needleNormalized = mb_strtolower((string) $normalized);

                $hitsNumber = str_contains(mb_strtolower((string) $s->number), $needle)
                    || str_contains(mb_strtolower(preg_replace('/[\s\-]+/u', '', (string) $s->number)), $needleNormalized);
                $hitsErp = $s->erp_number !== null && (
                    str_contains(mb_strtolower((string) $s->erp_number), $needle)
                    || str_contains(mb_strtolower(preg_replace('/[\s\-]+/u', '', (string) $s->erp_number)), $needleNormalized)
                );

                if (! $hitsNumber && ! $hitsErp) {
                    $matchSource = 'composition';
                    $matchProduct = $this->pickMatchedProduct($s, $q);
                } elseif ($hitsErp && ! $hitsNumber) {
                    $matchSource = 'erp_number';
                }
            }

            return [
                'id' => $s->id,
                'uuid' => $s->uuid,
                'number' => $s->number,
                'erp_number' => $s->erp_number,
                'date' => $s->date?->format('d.m.Y'),
                'total_amount' => $s->total_amount,
                'currency_code' => $s->currency_code,
                'items_count' => $s->items_count,
                'open_returns_count' => (int) ($s->open_returns_count ?? 0),
                'match_source' => $matchSource,
                'match_product' => $matchProduct,
                'label' => 'Реализация '.$s->number.($s->date ? ' от '.$s->date->format('d.m.Y') : ''),
            ];
        });
    }

    /**
     * Первый товар в составе реализации, давший совпадение с запросом.
     *
     * @return array<string, mixed>|null
     */
    private function pickMatchedProduct(Shipment $shipment, string $q): ?array
    {
        $needle = mb_strtolower($q);

        foreach ($shipment->items as $item) {
            $product = $item->product;

            if (! $product) {
                continue;
            }

            $hit = str_contains(mb_strtolower((string) $product->name), $needle)
                || str_contains(mb_strtolower((string) $product->sku), $needle)
                || str_contains(mb_strtolower((string) $product->code), $needle)
                || ($product->brand && str_contains(mb_strtolower((string) $product->brand->name), $needle));

            if ($hit) {
                return ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku];
            }
        }

        return null;
    }
}
