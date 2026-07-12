<?php

namespace App\Services\Erp\Handlers;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\Support\OrderStatusMapper;
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
            $status = $payload['status'] ?? OrderStatus::PENDING_APPROVAL->value;
            $type = $payload['type'] ?? 'order';

            // --- Пользователь ---
            $userId = null;
            if ($partnerUuid) {
                $user = User::where('erp_id', $partnerUuid)->first();
                $userId = $user?->id;
            }

            // --- Контрагент (компания) ---
            // Матчинг v13.3: приоритет по UUID (Company.erp_id), fallback по tax_id + tax_code
            // без user_id (ИНН/КПП юридически уникальны). lockForUpdate защищает от race
            // в окне SELECT → INSERT, UNIQUE-индекс БД — последний рубеж.
            $companyId = null;
            if ($contractorData && ! empty($contractorData['tax_id'])) {
                $contractorUuid = $contractorData['uuid'] ?? null;
                $contractorTaxId = $contractorData['tax_id'];
                $contractorTaxCode = $contractorData['tax_code'] ?? '';

                $company = null;

                if ($contractorUuid) {
                    $company = Company::withoutGlobalScopes()
                        ->withTrashed()
                        ->where('erp_id', $contractorUuid)
                        ->lockForUpdate()
                        ->first();
                }

                if (! $company) {
                    // tax_code: до миграции v13.3 в БД могут лежать NULL — трактуем NULL и '' как один ключ.
                    $company = Company::withoutGlobalScopes()
                        ->withTrashed()
                        ->where('tax_id', $contractorTaxId)
                        ->where(function ($q) use ($contractorTaxCode) {
                            $q->where('tax_code', $contractorTaxCode);
                            if ($contractorTaxCode === '') {
                                $q->orWhereNull('tax_code');
                            }
                        })
                        ->lockForUpdate()
                        ->first();

                    if ($company && $contractorUuid && ! $company->erp_id) {
                        Company::withoutEvents(function () use ($company, $contractorUuid) {
                            $company->update(['erp_id' => $contractorUuid]);
                        });
                    }
                }

                if ($company && $company->trashed()) {
                    $company->restoreQuietly();
                }

                if (! $company) {
                    $company = Company::withoutEvents(function () use ($contractorData, $contractorUuid, $userId, $contractorTaxCode) {
                        return Company::create([
                            'user_id' => $userId,
                            'erp_id' => $contractorUuid,
                            'country' => $contractorData['country'] ?? 'RU',
                            'name' => $contractorData['name'] ?? null,
                            'legal_name' => $contractorData['legal_name'] ?? $contractorData['name'] ?? null,
                            'tax_id' => $contractorData['tax_id'],
                            'registration_number' => $contractorData['registration_number'] ?? null,
                            'tax_code' => $contractorTaxCode,
                            'okpo_code' => $contractorData['okpo_code'] ?? null,
                            'legal_address' => $contractorData['legal_address'] ?? null,
                            'actual_address' => $contractorData['actual_address'] ?? null,
                            'phone' => $contractorData['phone'] ?? null,
                            'email' => $contractorData['email'] ?? null,
                        ]);
                    });

                    Log::info('HandleOrderCreated: контрагент создан автоматически', [
                        'tax_id' => $contractorTaxId,
                        'tax_code' => $contractorTaxCode,
                        'erp_id' => $contractorUuid,
                        'company_id' => $company->id,
                    ]);
                }

                $companyId = $company->id;

                if (! $userId && $company->user_id) {
                    $userId = $company->user_id;
                }
            }

            // Маппинг статусов из 1С (docs-erp/content/rules/orders.md).
            // 1С может прислать либо канонический английский ключ из enum,
            // либо русское название из перечисления — оба варианта поддерживаем
            // через OrderStatusMapper. Если 1С прислала "Удалён/deleted" —
            // переводим заказ в `closed` и одновременно soft-delete.
            $shouldSoftDelete = OrderStatusMapper::isDeletedMarker($status);
            $mappedStatus = OrderStatusMapper::toCanonical($status);

            if ($mappedStatus === null) {
                Log::warning('HandleOrderCreated: нераспознанный статус из 1С, использую pending_approval', [
                    'uuid' => $uuid,
                    'status_raw' => $status,
                ]);
                $mappedStatus = OrderStatus::PENDING_APPROVAL->value;
            }

            $finalStatus = $mappedStatus;

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

                // v15.3: способ доставки (delivery | pickup, опционально).
                // Для нового заказа отсутствие/null = delivery (default колонки).
                // При upsert отсутствие поля не сбрасывает сохранённое значение.
                if (! empty($payload['delivery_method'])) {
                    $fields['delivery_method'] = $payload['delivery_method'];
                }

                // v13.7: аудит-метки 1С (опционально). При отсутствии в payload
                // на upsert не перезаписываем — пишем только то, что прислали.
                // TZ-нормализация — в App\Casts\ErpDatetime.
                if (array_key_exists('erp_created_at', $payload)) {
                    $fields['erp_created_at'] = $payload['erp_created_at'];
                }
                if (array_key_exists('erp_updated_at', $payload)) {
                    $fields['erp_updated_at'] = $payload['erp_updated_at'];
                }

                if ($existingOrder) {
                    if ($existingOrder->trashed()) {
                        $existingOrder->restoreQuietly();
                    }
                    // comment — пользовательское поле, оставляем то, что ввёл клиент при оформлении.
                    // 1С может прислать его в payload, но при upsert мы его игнорируем.
                    unset($fields['comment']);
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
                    ? Product::withoutGlobalScopes()->where('external_id', $productUuid)->first()
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

            // Если 1С создала/обновила заказ сразу в статусе «Удалён» — soft-delete
            // (status уже выставлен в closed выше). Не диспатчим OrderDeleted,
            // иначе сайт опубликует событие обратно в 1С.
            if ($shouldSoftDelete && ! $order->trashed()) {
                $order->deleteQuietly();
            }

            $action = $existingOrder ? 'обновлён (upsert)' : 'создан от менеджера';
            Log::info("HandleOrderCreated: заказ {$action}", [
                'uuid' => $uuid,
                'number' => $order->number,
                'user_id' => $userId,
                'company_id' => $companyId,
                'delivery_address' => $payload['delivery_address'] ?? null,
                'delivery_method' => $payload['delivery_method'] ?? null,
                'items_count' => count($items),
                'total_amount' => $totalAmount,
            ]);
        });
    }
}
