<?php

namespace App\Services\Erp\Handlers;

use App\Models\Agreement;
use App\Models\AgreementDiscount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleAgreementCreated
{
    public function handle(array $payload): void
    {
        $data = $payload['data'] ?? $payload;

        $uuid = $data['uuid'] ?? null;
        $name = $data['name'] ?? null;
        $partnerUuid = $data['partner_uuid'] ?? null;
        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $data['ends_at'] ?? null;
        
        $discounts = $data['discounts'] ?? [];

        if (!$uuid || !$partnerUuid) {
            Log::warning('agreement.created: отсутствует uuid или partner_uuid', ['payload' => $payload]);
            return;
        }

        DB::beginTransaction();
        try {
            $agreement = Agreement::updateOrCreate(
                ['uuid' => $uuid],
                [
                    'name' => $name,
                    'partner_uuid' => $partnerUuid,
                    'starts_at' => $startsAt ? \Carbon\Carbon::parse($startsAt) : null,
                    'ends_at' => $endsAt ? \Carbon\Carbon::parse($endsAt) : null,
                    'is_active' => true,
                ]
            );

            $existingDiscountUuids = [];

            foreach ($discounts as $discountData) {
                $discountUuid = $discountData['discount_uuid'] ?? null;
                if (!$discountUuid) continue;

                $existingDiscountUuids[] = $discountUuid;

                AgreementDiscount::updateOrCreate(
                    [
                        'agreement_id' => $agreement->id,
                        'discount_uuid' => $discountUuid,
                    ],
                    [
                        'name' => $discountData['name'] ?? $discountData['discount_name'] ?? 'Без названия',
                        'percentage' => $discountData['percentage'] ?? $discountData['value'] ?? 0,
                        'product_segment_uuid' => $discountData['product_segment_uuid'] ?? null,
                    ]
                );
            }

            // Удаляем скидки, которых больше нет в соглашении
            $agreement->discounts()->whereNotIn('discount_uuid', $existingDiscountUuids)->delete();

            DB::commit();

            Log::info('agreement.created: индивидуальное соглашение создано/обновлено', [
                'agreement_id' => $agreement->id,
                'uuid' => $uuid,
                'discounts_count' => count($existingDiscountUuids),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('agreement.created error: ' . $e->getMessage(), ['uuid' => $uuid]);
            throw $e;
        }
    }
}
