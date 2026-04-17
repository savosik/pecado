<?php

namespace App\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * US-07 v3: Обработка события order.created из 1С (заказ от менеджера).
 *
 * Менеджер создаёт заказ вручную в 1С — данные передаются на сайт.
 * Если контрагент не найден — создаётся автоматически из данных заказа.
 * Если товар для позиции не найден — OrderItem создаётся с name, но без product_id.
 *
 * Заказ создаётся через Model::withoutEvents() чтобы не публиковать
 * его обратно в 1С через PublishOrderToErp listener.
 */
class HandleOrderCreated
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandleOrderCreated: отсутствует обязательное поле uuid', [
                'payload' => $payload,
            ]);

            return;
        }

        DB::transaction(function () use ($payload, $uuid) {
            $partnerUuid = $payload['partner_uuid'] ?? null;
            $contractorData = $payload['contractor'] ?? null;
            $items = $payload['items'] ?? [];
            $status = $payload['status'] ?? 'pending';
            $type = $payload['type'] ?? 'order';

            // --- Пользователь ---
            $userId = null;
            if ($partnerUuid) {
                $user = User::where('erp_id', $partnerUuid)->first();
                $userId = $user?->id;
            }

            // --- Контрагент (компания) ---
            $companyId = null;
            if ($contractorData && ! empty($contractorData['tax_id'])) {
                $company = Company::withoutGlobalScopes()
                    ->where('tax_id', $contractorData['tax_id'])
                    ->first();

                if (! $company) {
                    // Авто-создание компании из данных контрагента
                    $company = Company::withoutEvents(function () use ($contractorData, $userId) {
                        return Company::create([
                            'user_id' => $userId,
                            'country' => $contractorData['country'] ?? 'RU',
                            'name' => $contractorData['name'] ?? null,
                            'legal_name' => $contractorData['legal_name'] ?? $contractorData['name'] ?? null,
                            'tax_id' => $contractorData['tax_id'],
                            'registration_number' => $contractorData['registration_number'] ?? null,
                            'tax_code' => $contractorData['tax_code'] ?? null,
                            'okpo_code' => $contractorData['okpo_code'] ?? null,
                            'legal_address' => $contractorData['legal_address'] ?? null,
                            'actual_address' => $contractorData['actual_address'] ?? null,
                            'phone' => $contractorData['phone'] ?? null,
                            'email' => $contractorData['email'] ?? null,
                        ]);
                    });

                    Log::info('HandleOrderCreated: контрагент создан автоматически', [
                        'tax_id' => $contractorData['tax_id'],
                        'company_id' => $company->id,
                    ]);
                }

                $companyId = $company->id;

                // Если пользователь не найден по partner_uuid, берём владельца компании
                if (! $userId && $company->user_id) {
                    $userId = $company->user_id;
                }
            }

            // Маппинг статусов из 1С (docs-erp/content/rules/orders.md)
            $statusMap = [
                'не согласован' => 'pending',
                'к выполнению' => 'confirmed',
                'к отгрузке' => 'ready_to_ship',
                'к_отгрузке' => 'ready_to_ship',
                'закрыт' => 'closed',
                'удален' => 'deleted',
                'удалён' => 'deleted',
                'deleted' => 'deleted',
            ];

            $normalizedStatus = mb_strtolower(trim($status));
            $finalStatus = $statusMap[$normalizedStatus] ?? $status;

            // --- Upsert заказа по uuid (без диспатча событий — не публикуем обратно в ERP) ---
            // withTrashed: если заказ был soft-deleted и 1С повторно шлёт его — восстанавливаем.
            $existingOrder = Order::withTrashed()->where('uuid', $uuid)->first();

            $order = Order::withoutEvents(function () use ($existingOrder, $uuid, $payload, $userId, $companyId, $finalStatus, $type) {
                $fields = [
                    'number' => $payload['number'] ?? null,
                    'erp_number' => $payload['number'] ?? null,
                    'user_id' => $userId,
                    'company_id' => $companyId,
                    'delivery_address' => $payload['delivery_address'] ?? null,
                    'status' => $finalStatus,
                    'type' => $type,
                    'currency_code' => $payload['currency_code'] ?? 'RUB',
                    'exchange_rate' => $payload['exchange_rate'] ?? 1.0,
                    'rate_coefficient' => $payload['rate_coefficient'] ?? 1.0,
                    'comment' => $payload['comment'] ?? null,
                ];

                if ($existingOrder) {
                    if ($existingOrder->trashed()) {
                        $existingOrder->restoreQuietly();
                    }
                    $existingOrder->updateQuietly($fields);

                    return $existingOrder;
                }

                return Order::create(array_merge($fields, [
                    'uuid' => $uuid,
                    'total_amount' => 0,
                ]));
            });

            // --- Позиции заказа (полная замена при upsert) ---
            if ($existingOrder) {
                $order->items()->delete();
            }

            $totalAmount = 0;

            foreach ($items as $item) {
                $productUuid = $item['product_uuid'] ?? null;
                $product = $productUuid
                    ? Product::where('external_id', $productUuid)->first()
                    : null;

                $quantity = $item['quantity'] ?? 0;
                $basePrice = $item['base_price'] ?? $item['price'] ?? 0;
                $discountPercent = $item['discount_percent'] ?? 0;
                $finalPrice = $item['final_price'] ?? $item['price'] ?? $basePrice;
                $subtotal = round($quantity * $finalPrice, 2);

                // Если товар не найден — сохраняем название (товара может уже не быть)
                $name = $product
                    ? $product->name
                    : ($item['name'] ?? $productUuid ?? 'Неизвестный товар');

                $order->items()->create([
                    'product_id' => $product?->id,
                    'name' => $name,
                    'quantity' => $quantity,
                    'price' => $finalPrice,
                    'base_price' => $basePrice,
                    'discount_percent' => $discountPercent,
                    'final_price' => $finalPrice,
                    'subtotal' => $subtotal,
                ]);

                $totalAmount += $subtotal;
            }

            // Обновляем сумму заказа
            $order->updateQuietly(['total_amount' => $totalAmount]);

            $action = $existingOrder ? 'обновлён (upsert)' : 'создан от менеджера';
            Log::info("HandleOrderCreated: заказ {$action}", [
                'uuid' => $uuid,
                'number' => $order->number,
                'user_id' => $userId,
                'company_id' => $companyId,
                'delivery_address' => $payload['delivery_address'] ?? null,
                'items_count' => count($items),
                'total_amount' => $totalAmount,
            ]);
        });
    }
}
