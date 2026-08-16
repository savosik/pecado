<?php

namespace App\Console\Commands;

use App\Services\Stock\StockBufferRecalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ночной пересчёт страхового буфера по рисковым SKU (эпик buf-00, buf-01).
 *
 * Только считает и складывает в product_stock_buffers: занижение показа
 * включается отдельно флагом STOCK_BUFFER_ENABLED (buf-04). Ручные пометки
 * склада (manual_qty) не трогает никогда.
 */
class RecomputeStockBuffers extends Command
{
    protected $signature = 'stock:buffers:recompute';

    protected $description = 'Пересчитать страховой буфер остатков по сигналам риска (отмены, брак, срок годности)';

    public function handle(StockBufferRecalculator $recalculator): int
    {
        $result = $recalculator->recompute();

        $this->info(sprintf(
            'Буферы: %d SKU с сигналами, скрыто %d шт, изменилось %d SKU.',
            $result['rows'],
            $result['hidden_units'],
            count($result['changed']),
        ));

        foreach ($result['changed'] as $productId => $diff) {
            $this->line(sprintf('  товар #%d: %d → %d', $productId, $diff['before'], $diff['after']));
        }

        // Дифф — фундамент условной инвалидации кешей (buf-05): пустой список
        // означает, что ни один кеш трогать не нужно.
        Log::info('stock-buffer: пересчёт завершён', [
            'rows' => $result['rows'],
            'hidden_units' => $result['hidden_units'],
            'changed' => $result['changed'],
        ]);

        return self::SUCCESS;
    }
}
