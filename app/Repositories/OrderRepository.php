<?php

namespace App\Repositories;

use App\Contracts\Order\OrderRepositoryInterface;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderRepository implements OrderRepositoryInterface
{
    public function getPaginatedForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        if ($user->is_admin) {
            return Order::with(['items', 'user'])
                ->latest()
                ->paginate($perPage);
        }

        return Order::with(['items'])
            ->where('user_id', $user->id)
            ->latest()
            ->paginate($perPage);
    }

    public function findWithRelations(int $id, array $relations = []): ?Order
    {
        return Order::with($relations)->find($id);
    }

    public function findByUuid(string $uuid): ?Order
    {
        return Order::where('uuid', $uuid)->first();
    }

    public function create(array $data): Order
    {
        return Order::create($data);
    }

    public function update(Order $order, array $data): Order
    {
        $order->update($data);
        return $order->refresh();
    }
}
