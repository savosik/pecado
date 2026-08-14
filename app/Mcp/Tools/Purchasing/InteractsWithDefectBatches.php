<?php

namespace App\Mcp\Tools\Purchasing;

use App\Http\Middleware\AuthenticatePurchasingAgent;
use App\Models\ProductDefect;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;

/**
 * Общая часть инструментов сервера закупок: актор, ответы и представление партии.
 *
 * Представление партии повторяет админский `Admin\DefectController::presentDefect`,
 * а не переизобретает его: агент закупщика и человек в /admin/defects должны
 * видеть одну и ту же партию одинаково.
 */
trait InteractsWithDefectBatches
{
    protected function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * JSON-ответ с читаемой кириллицей.
     *
     * Штатный `Response::json()` кодирует без JSON_UNESCAPED_UNICODE, и весь
     * русский текст уезжает агенту в виде `\uXXXX`-последовательностей: клиент
     * это разберёт, но заплатит втрое больше токенов за те же слова.
     *
     * @param  array<string, mixed>  $data
     */
    protected function payload(array $data): Response
    {
        return Response::text(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Аудит операций записи: что сделал агент, чьими правами и каким токеном.
     * Чтение не журналируется — иначе журнал утонет в шуме.
     *
     * @param  array<string, mixed>  $context
     */
    protected function audit(string $operation, array $context): void
    {
        Log::channel('purchasing-agent')->info($operation, $context + [
            'user_id' => $this->actor()?->id,
            'token' => request()->attributes->get(AuthenticatePurchasingAgent::TOKEN_NAME_ATTRIBUTE),
        ]);
    }

    /**
     * @param  array<int, int>  $availableMap
     * @param  array<int, array<string, mixed>>  $referenceMap
     * @return array<string, mixed>
     */
    protected function present(ProductDefect $defect, array $availableMap, array $referenceMap = []): array
    {
        return [
            'id' => $defect->id,
            'defect_description' => $defect->defect_description,
            'quantity' => $defect->quantity,
            'available_quantity' => $availableMap[$defect->id] ?? 0,
            'reserved_quantity' => $defect->quantity - ($availableMap[$defect->id] ?? 0),
            'price' => $defect->price !== null ? (float) $defect->price : null,
            'is_published' => $defect->is_published,
            'closed_at' => $defect->closed_at?->toIso8601String(),
            'closed_reason_label' => $defect->closed_reason?->label(),
            'created_at' => $defect->created_at?->toIso8601String(),
            'reference_price' => $referenceMap[$defect->product_id] ?? null,
            'product' => [
                'id' => $defect->product->id,
                'name' => $defect->product->name,
                'sku' => $defect->product->sku,
            ],
            'warehouse' => [
                'id' => $defect->warehouse->id,
                'name' => $defect->warehouse->name,
            ],
            'photos' => $defect->getMedia(ProductDefect::MEDIA_COLLECTION)->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl(),
            ])->values()->all(),
        ];
    }
}
