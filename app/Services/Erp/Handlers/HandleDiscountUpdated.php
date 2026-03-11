<?php

namespace App\Services\Erp\Handlers;

use App\Models\Discount;
use App\Models\PartnerSegment;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleDiscountUpdated
{
    /**
     * Обработка события discount.updated из 1С.
     *
     * Обновляет скидку по UUID, пересинхронизирует привязки к товарам,
     * партнёрам и сегментам (US-03 v2).
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $type = $payload['type'] ?? null;
        $value = $payload['value'] ?? null;
        $startsAt = $payload['starts_at'] ?? null;
        $endsAt = $payload['ends_at'] ?? null;
        $productUuids = $payload['product_uuids'] ?? [];
        $partnerUuids = $payload['partner_uuids'] ?? [];
        // US-03 v2: сегменты номенклатуры и партнёров
        $productSegmentUuids = $payload['product_segment_uuids'] ?? [];
        $partnerSegmentUuids = $payload['partner_segment_uuids'] ?? [];

        if (!$uuid || $value === null) {
            Log::warning('discount.updated: отсутствует uuid или value', ['payload' => $payload]);

            return;
        }

        $discount = Discount::withTrashed()->where('external_id', $uuid)->first();

        if (!$discount) {
            Log::info('discount.updated: скидка не найдена по UUID, событие проигнорировано', [
                'uuid' => $uuid,
            ]);

            return;
        }

        DB::beginTransaction();
        try {
            $discount->update([
                'type' => $type,
                'percentage' => $value,
                'is_posted' => true,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'deleted_at' => null,
            ]);

            // Пересинхронизация товаров по UUID
            $productIds = [];
            if (!empty($productUuids)) {
                $productIds = Product::whereIn('external_id', $productUuids)->pluck('id')->toArray();
            }
            $discount->products()->sync($productIds);

            // Пересинхронизация пользователей по partner UUID
            $userIds = [];
            if (!empty($partnerUuids)) {
                $userIds = User::whereIn('erp_id', $partnerUuids)->pluck('id')->toArray();
            }
            $discount->users()->sync($userIds);

            // US-03 v2: пересинхронизация сегментов номенклатуры
            $productSegmentIds = [];
            if (!empty($productSegmentUuids)) {
                $productSegmentIds = ProductSegment::whereIn('uuid', $productSegmentUuids)->pluck('id')->toArray();
            }
            $discount->productSegments()->sync($productSegmentIds);

            // US-03 v2: пересинхронизация сегментов партнёров
            $partnerSegmentIds = [];
            if (!empty($partnerSegmentUuids)) {
                $partnerSegmentIds = PartnerSegment::whereIn('uuid', $partnerSegmentUuids)->pluck('id')->toArray();
            }
            $discount->partnerSegments()->sync($partnerSegmentIds);

            DB::commit();

            Log::info('discount.updated: скидка обновлена', [
                'discount_id' => $discount->id,
                'uuid' => $uuid,
                'type' => $type,
                'value' => $value,
                'products_linked' => count($productIds),
                'users_linked' => count($userIds),
                'product_segments_linked' => count($productSegmentIds),
                'partner_segments_linked' => count($partnerSegmentIds),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
