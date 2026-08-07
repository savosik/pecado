<?php

namespace App\Services\Erp\Handlers;

use App\Services\Erp\Support\GoodsIssuePayloadMapper;
use Illuminate\Support\Facades\Log;

/**
 * Обработка события goods_issue.updated из 1С (US-20, v15.15.0).
 *
 * Основной сценарий — движение ордера по статусам: Подготовлен → К отбору → К проверке →
 * Проверен → К отгрузке → Отгружен. 1С присылает документ целиком, поэтому запись
 * не отличается от `created` — вся логика в общем маппере.
 *
 * Ордера, которого сайт ещё не видел, `updated` не пропускает: документ создаётся.
 * Иначе потерянное или пришедшее не по порядку `created` навсегда оставило бы склад
 * без ордера, а восстановить его можно только повторной выгрузкой из 1С.
 */
class HandleGoodsIssueUpdated
{
    public function __construct(private readonly GoodsIssuePayloadMapper $mapper) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $goodsIssue = $this->mapper->apply($payload, 'HandleGoodsIssueUpdated');

        if (! $goodsIssue) {
            return;
        }

        Log::info('HandleGoodsIssueUpdated: расходный ордер обновлён', [
            'uuid' => $goodsIssue->uuid,
            'number' => $goodsIssue->number,
            'status' => $goodsIssue->status,
        ]);
    }
}
