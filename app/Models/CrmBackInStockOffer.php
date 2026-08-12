<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отметка «этот вернувшийся товар этому клиенту уже предлагали».
 *
 * Живёт отдельно от письма намеренно: черновик менеджер может удалить,
 * а факт предложения должен пережить удаление — иначе следующий прогон
 * предложит то же самое ещё раз.
 *
 * @property int $id
 * @property int $client_user_id
 * @property int $product_id
 * @property int|null $email_id
 * @property \Illuminate\Support\Carbon $offered_at
 */
class CrmBackInStockOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_user_id',
        'product_id',
        'email_id',
        'offered_at',
    ];

    protected function casts(): array
    {
        return ['offered_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(CrmEmail::class, 'email_id');
    }
}
