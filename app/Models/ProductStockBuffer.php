<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Страховой буфер остатка по рисковому товару (эпик buf-00).
 *
 * Буфер виртуальный: остатки в product_warehouse не трогаются, занижается
 * только показ для клиентов сегмента. Эффективный размер — max(manual_qty
 * ?? buffer_qty, 0): ручная пометка склада всегда побеждает расчёт.
 *
 * @property int $id
 * @property int $product_id
 * @property int $buffer_qty Рассчитанный размер буфера, шт
 * @property int|null $manual_qty Ручной override со склада (WMS); задан — побеждает расчёт
 * @property array|null $reasons Раскладка сигналов риска: {"cancellations": N, "defect_batches": N, "shelf_life": true}
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property int|null $manual_set_by Кто поставил ручную пометку (users.id)
 * @property \Illuminate\Support\Carbon|null $manual_set_at Когда поставлена ручная пометка
 * @property-read Product $product
 * @property-read User|null $manualAuthor
 */
class ProductStockBuffer extends Model
{
    protected $fillable = [
        'product_id',
        'buffer_qty',
        'manual_qty',
        'reasons',
        'computed_at',
        'manual_set_by',
        'manual_set_at',
    ];

    protected function casts(): array
    {
        return [
            'buffer_qty' => 'integer',
            'manual_qty' => 'integer',
            'reasons' => 'array',
            'computed_at' => 'datetime',
            'manual_set_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function manualAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_set_by');
    }

    /**
     * Эффективный размер буфера: ручная пометка побеждает расчёт.
     */
    public function effectiveQty(): int
    {
        return max((int) ($this->manual_qty ?? $this->buffer_qty), 0);
    }
}
