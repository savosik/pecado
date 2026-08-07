<?php

namespace App\Services\Erp\Support;

use App\Models\GoodsIssue;
use App\Models\GoodsIssueStatusHistory;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Разбор payload расходного ордера и запись его в БД (US-20).
 *
 * Общий код для `goods_issue.created` и `goods_issue.updated`: 1С присылает документ
 * целиком в обоих случаях, и различать их на уровне записи нечем. Handler-ы остаются
 * тонкими, а вся логика живёт здесь — по образцу {@see PaymentPayloadMapper}.
 */
class GoodsIssuePayloadMapper
{
    use ResolvesContractorParty;
    use ResolvesDocumentOrganization;

    /**
     * Создать или обновить ордер по payload из 1С.
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $context  Имя handler-а для логов
     */
    public function apply(array $payload, string $context): ?GoodsIssue
    {
        $uuid = $payload['uuid'] ?? null;

        if (! is_string($uuid) || trim($uuid) === '') {
            Log::warning($context.': отсутствует uuid', ['payload' => $payload]);

            return null;
        }

        $uuid = trim($uuid);

        [$companyId, $userId] = $this->resolveCompanyAndUser(
            $payload['contractor_uuid'] ?? null,
            $payload['tax_id'] ?? null,
            $payload['partner_uuid'] ?? null,
        );

        $fields = [
            'number' => $payload['number'] ?? null,
            'date' => $payload['date'] ?? null,
            'shipment_date' => $payload['shipment_date'] ?? null,
            'status' => $payload['status'] ?? GoodsIssue::STATUS_PREPARED,
            'operation' => $payload['operation'] ?? null,
            'warehouse_uuid' => $payload['warehouse_uuid'] ?? null,
            'company_id' => $companyId,
            'user_id' => $userId,
            'contractor_uuid' => $payload['contractor_uuid'] ?? null,
            'tax_id' => $payload['tax_id'] ?? null,
            'recipient_name' => $payload['recipient_name'] ?? null,
            'responsible' => $payload['responsible'] ?? null,
            'priority' => $payload['priority'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'delivery_type' => $payload['delivery_type'] ?? null,
            'delivery_address' => $payload['delivery_address'] ?? null,
            'delivery_order' => $payload['delivery_order'] ?? null,
        ];

        // Аудит-метки 1С: передача null — явная установка, отсутствие ключа —
        // не трогаем существующее значение. TZ-нормализация в App\Casts\ErpDatetime.
        foreach (['erp_created_at', 'erp_updated_at'] as $auditField) {
            if (array_key_exists($auditField, $payload)) {
                $fields[$auditField] = $payload[$auditField];
            }
        }

        // Организация и склад проведения: отсутствие поля не сбрасывает сохранённое.
        $fields = array_merge($fields, $this->resolveOrganizationFields($payload, $context));

        return DB::transaction(function () use ($uuid, $fields, $payload, $context): GoodsIssue {
            $goodsIssue = GoodsIssue::withTrashed()->where('uuid', $uuid)->first();

            $previousStatus = $goodsIssue?->status;

            if ($goodsIssue) {
                if ($goodsIssue->trashed()) {
                    // 1С отменила проведение и провела заново — возвращаем документ складу.
                    $goodsIssue->restore();
                }
                $goodsIssue->update($fields);
            } else {
                $goodsIssue = GoodsIssue::create($fields + ['uuid' => $uuid]);
            }

            $this->recordStatusChange($goodsIssue, $previousStatus);

            if (isset($payload['items']) && is_array($payload['items'])) {
                $this->syncItems($goodsIssue, $payload['items'], $context);
            }

            // Отсутствие ключа и пустой массив означают разное: ключа нет — не трогаем
            // сохранённые упаковки, [] — очищаем. Та же семантика, что у payment_schedule.
            if (array_key_exists('packages', $payload) && is_array($payload['packages'])) {
                $this->syncPackages($goodsIssue, $payload['packages']);
            }

            return $goodsIssue;
        });
    }

    /**
     * Зафиксировать переход статуса.
     *
     * Строка пишется только при фактической смене: 1С досылает документ целиком при
     * любом изменении, и без этой проверки журнал забился бы повторами одного статуса.
     */
    private function recordStatusChange(GoodsIssue $goodsIssue, ?string $previousStatus): void
    {
        if ($previousStatus === $goodsIssue->status) {
            return;
        }

        $changedAt = now();

        GoodsIssueStatusHistory::create([
            'goods_issue_id' => $goodsIssue->id,
            'from_status' => $previousStatus,
            'to_status' => $goodsIssue->status,
            'changed_at' => $changedAt,
            'source' => GoodsIssueStatusHistory::SOURCE_ERP,
        ]);

        $goodsIssue->forceFill(['status_changed_at' => $changedAt])->save();
    }

    /**
     * Полная замена строк ордера.
     *
     * Именно замена, а не merge: 1С присылает табличную часть целиком, и «дописать
     * недостающие» означало бы дублировать строки при каждом перепроведении.
     *
     * @param  array<int, mixed>  $items
     */
    private function syncItems(GoodsIssue $goodsIssue, array $items, string $context): void
    {
        $goodsIssue->items()->delete();

        $itemsCount = 0;
        $totalQuantity = 0.0;
        $unresolvedCount = 0;

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $productUuid = $item['product_uuid'] ?? null;

            // Скрытые товары читаем тоже: ордера уезжают и по снятой с публикации
            // номенклатуре, и кладовщику она нужна не меньше остальной.
            $product = is_string($productUuid) && $productUuid !== ''
                ? Product::withoutGlobalScopes()->where('external_id', $productUuid)->first()
                : null;

            if (! $product) {
                $unresolvedCount++;
            }

            $orderUuid = $item['order_uuid'] ?? null;
            $order = is_string($orderUuid) && $orderUuid !== ''
                ? Order::withoutGlobalScopes()->where('uuid', $orderUuid)->first()
                : null;

            $quantity = (float) ($item['quantity'] ?? 0);

            $goodsIssue->items()->create([
                'line_number' => $item['line_number'] ?? $index + 1,
                'product_id' => $product?->id,
                'product_uuid' => $productUuid,
                'product_name' => $item['product_name'] ?? $product?->name,
                'order_id' => $order?->id,
                'order_uuid' => $orderUuid,
                'order_number' => $item['order_number'] ?? $order?->erp_number,
                'order_date' => $item['order_date'] ?? null,
                'quantity' => $quantity,
                'unit' => $item['unit'] ?? null,
                'package_number' => $item['package_number'] ?? null,
            ]);

            $itemsCount++;
            $totalQuantity += $quantity;
        }

        $goodsIssue->forceFill([
            'items_count' => $itemsCount,
            'total_quantity' => $totalQuantity,
            'unresolved_items_count' => $unresolvedCount,
        ])->save();

        if ($unresolvedCount > 0) {
            Log::info($context.': часть позиций не привязана к каталогу', [
                'uuid' => $goodsIssue->uuid,
                'unresolved' => $unresolvedCount,
                'total' => $itemsCount,
            ]);
        }
    }

    /**
     * Полная замена упаковочных листов.
     *
     * @param  array<int, mixed>  $packages
     */
    private function syncPackages(GoodsIssue $goodsIssue, array $packages): void
    {
        $goodsIssue->packages()->delete();

        $count = 0;
        $seenNumbers = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ! isset($package['number'])) {
                continue;
            }

            $number = (int) $package['number'];

            // Номер места уникален в пределах ордера. Повтор в payload — ошибка 1С,
            // но ронять весь документ из-за неё нельзя: ордер нужен складу целиком.
            if (isset($seenNumbers[$number])) {
                continue;
            }
            $seenNumbers[$number] = true;

            $goodsIssue->packages()->create([
                'number' => $number,
                'positions_count' => $package['positions_count'] ?? null,
                'weight' => $package['weight'] ?? null,
                'volume' => $package['volume'] ?? null,
            ]);

            $count++;
        }

        $goodsIssue->forceFill(['packages_count' => $count])->save();
    }
}
