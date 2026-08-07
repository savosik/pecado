<?php

namespace App\Services\Erp\Handlers;

use App\Models\GoodsIssue;
use App\Models\GoodsIssueStatusHistory;
use Illuminate\Support\Facades\Log;

/**
 * Обработка события goods_issue.deleted из 1С (US-20, v15.15.0).
 *
 * 1С отменила проведение ордера или пометила его на удаление. Ордер уходит из журнала
 * склада, но данные сохраняются: удаление мягкое, состав и история статусов остаются.
 * Повторное проведение того же документа вернёт ордер как был.
 */
class HandleGoodsIssueDeleted
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! is_string($uuid) || trim($uuid) === '') {
            Log::warning('HandleGoodsIssueDeleted: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $goodsIssue = GoodsIssue::where('uuid', trim($uuid))->first();

        if (! $goodsIssue) {
            // Неизвестный ордер — не ошибка: 1С могла отменить документ, который сайту
            // никогда не выгружался (создан и отменён между сеансами обмена).
            Log::info('HandleGoodsIssueDeleted: расходный ордер не найден', ['uuid' => $uuid]);

            return;
        }

        // Собственный статус ордера не трогаем: после восстановления документа
        // должно быть видно, на каком этапе его застала отмена.
        $goodsIssue->statusHistories()->create([
            'from_status' => $goodsIssue->status,
            'to_status' => GoodsIssueStatusHistory::STATUS_CANCELLED,
            'changed_at' => now(),
            'source' => GoodsIssueStatusHistory::SOURCE_ERP,
        ]);

        $goodsIssue->delete();

        Log::info('HandleGoodsIssueDeleted: расходный ордер помечен удалённым', [
            'uuid' => $uuid,
            'number' => $goodsIssue->number,
        ]);
    }
}
