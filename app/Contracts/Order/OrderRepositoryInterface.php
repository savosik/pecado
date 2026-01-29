<?php

namespace App\Contracts\Order;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrderRepositoryInterface
{
    /**
     * Get paginated orders for a user.
     * If user is admin, returns all orders.
     */
    public function getPaginatedForUser(User $user, int $perPage = 15): LengthAwarePaginator;

    /**
     * Find order by ID with relations.
     */
    public function findWithRelations(int $id, array $relations = []): ?Order;

    /**
     * Find order by UUID.
     */
    public function findByUuid(string $uuid): ?Order;

    /**
     * Create a new order.
     */
    public function create(array $data): Order;

    /**
     * Update an order.
     */
    public function update(Order $order, array $data): Order;
}
