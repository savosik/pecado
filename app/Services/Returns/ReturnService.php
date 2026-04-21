<?php

namespace App\Services\Returns;

use App\Enums\ReturnStatus;
use App\Events\ReturnCreated;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReturnService
{
    /**
     * Создать возврат от имени пользователя.
     *
     * @param  array{comment?: ?string, items: array<int, array{shipment_item_id: int, quantity: int, reason: string, reason_comment?: ?string}>}  $data
     */
    public function createForUser(User $user, array $data): ProductReturn
    {
        return $this->create($user, $data, false);
    }

    /**
     * Создать возврат от имени пользователя админом (без 403 при чужой реализации).
     *
     * @param  array{comment?: ?string, items: array<int, array{shipment_item_id: int, quantity: int, reason: string, reason_comment?: ?string}>}  $data
     */
    public function createForAnyUser(User $user, array $data): ProductReturn
    {
        return $this->create($user, $data, true);
    }

    protected function create(User $user, array $data, bool $admin): ProductReturn
    {
        return DB::transaction(function () use ($user, $data, $admin) {
            $rows = [];
            foreach ($data['items'] as $input) {
                $rows[] = $this->buildItemRow($user, $input, $admin);
            }

            $total = array_sum(array_column($rows, 'subtotal'));

            $return = ProductReturn::create([
                'user_id' => $user->id,
                'status' => ReturnStatus::PENDING->value,
                'comment' => $data['comment'] ?? null,
                'total_amount' => $total,
            ]);

            $return->items()->createMany($rows);

            event(new ReturnCreated($return->fresh('items')));

            return $return;
        });
    }

    /**
     * @param  array{shipment_item_id: int, quantity: int, reason: string, reason_comment?: ?string}  $input
     * @return array<string, mixed>
     */
    protected function buildItemRow(User $user, array $input, bool $admin): array
    {
        /** @var ShipmentItem $si */
        $si = ShipmentItem::with('shipment')
            ->lockForUpdate()
            ->findOrFail($input['shipment_item_id']);

        if (! $admin) {
            /** @var \App\Models\Shipment|null $shipment */
            $shipment = $si->shipment;
            abort_unless(
                $shipment !== null && $shipment->user_id === $user->id,
                403,
                'Реализация не принадлежит пользователю.'
            );
        }

        $already = (int) ReturnItem::where('shipment_item_id', $si->id)->sum('quantity');
        $available = (int) $si->quantity - $already;
        abort_if(
            $input['quantity'] > $available,
            422,
            "Доступно к возврату: {$available}."
        );

        $price = (float) $si->price;
        $quantity = (int) $input['quantity'];

        return [
            'shipment_item_id' => $si->id,
            'shipment_id' => $si->shipment_id,
            'product_id' => $si->product_id,
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => round($price * $quantity, 2),
            'reason' => $input['reason'],
            'reason_comment' => $input['reason_comment'] ?? null,
        ];
    }
}
