<?php

namespace App\Services\Erp\Handlers;

use App\Services\Erp\Support\GoodsIssuePayloadMapper;
use Illuminate\Support\Facades\Log;

/**
 * Обработка события goods_issue.created из 1С (US-20, v15.15.0).
 *
 * Расходный ордер на товары — складской документ, по которому товар отбирают,
 * проверяют, упаковывают и грузят. Приезжает при первом проведении ордера.
 *
 * Обработка идемпотентна по `uuid`: повторное сообщение не создаёт второй ордер,
 * а перезаписывает существующий. 1С шлёт `created` и после отмены проведения —
 * тогда ордер восстанавливается из мягкого удаления.
 */
class HandleGoodsIssueCreated
{
    public function __construct(private readonly GoodsIssuePayloadMapper $mapper) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $goodsIssue = $this->mapper->apply($payload, 'HandleGoodsIssueCreated');

        if (! $goodsIssue) {
            return;
        }

        Log::info('HandleGoodsIssueCreated: расходный ордер создан/обновлён', [
            'uuid' => $goodsIssue->uuid,
            'number' => $goodsIssue->number,
            'status' => $goodsIssue->status,
            'items' => $goodsIssue->items_count,
            'packages' => $goodsIssue->packages_count,
        ]);
    }
}
