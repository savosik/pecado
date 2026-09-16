<?php

namespace App\Services\Client\Api\Operations;

use App\Models\Company;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Client\Api\OperationRunner;
use App\Support\OperationApi\OperationInput;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Поиск своих записей по аргументам операции.
 *
 * Всё, что не принадлежит клиенту, — «не найдено», а не «запрещено»: существование
 * чужой записи агенту не подтверждается. Сюда же — разбор идентификаторов, которые
 * агент передаёт как удобно: заказ по id, номеру или uuid.
 */
trait ResolvesClientEntities
{
    /**
     * Юрлицо, разрешённое раннером для companyScoped-операции.
     */
    protected function company(OperationInput $input): Company
    {
        $company = $input->get(OperationRunner::COMPANY_ARG);

        if (! $company instanceof Company) {
            throw (new ModelNotFoundException)->setModel(Company::class);
        }

        return $company;
    }

    /**
     * Заказ клиента по id, номеру или uuid (в т. ч. мягко удалённый — отменённые резервы).
     */
    protected function orderOf(User $user, string|int $identifier, bool $withTrashed = false): Order
    {
        $query = Order::query()->where('user_id', $user->getKey());

        if ($withTrashed) {
            $query->withTrashed();
        }

        $identifier = trim((string) $identifier);

        if (ctype_digit($identifier)) {
            $query->whereKey((int) $identifier);
        } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            $query->where('uuid', $identifier);
        } else {
            $query->where('number', $identifier);
        }

        return $query->firstOrFail();
    }

    /**
     * Реализация клиента по id, uuid, номеру 1С или номеру (дефисы в номере не важны).
     */
    protected function shipmentOf(User $user, string|int $identifier): Shipment
    {
        $identifier = trim((string) $identifier);
        $query = Shipment::query()->where('user_id', $user->getKey());

        if (ctype_digit($identifier)) {
            return $query->whereKey((int) $identifier)->firstOrFail();
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            return $query->where('uuid', $identifier)->firstOrFail();
        }

        $normalized = str_replace('-', '', $identifier);

        return $query
            ->where(function ($q) use ($identifier, $normalized) {
                $q->where('erp_number', $identifier)
                    ->orWhere('number', $identifier)
                    ->orWhereRaw("REPLACE(number, '-', '') = ?", [$normalized])
                    ->orWhereRaw("REPLACE(COALESCE(erp_number, ''), '-', '') = ?", [$normalized]);
            })
            ->firstOrFail();
    }
}
