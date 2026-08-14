<?php

namespace App\Models;

use App\Enums\Substitution\LinkKind;
use App\Enums\Substitution\LinkSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Связь справочника замен: «предлагать to вместо from».
 *
 * Направленная намеренно: дешёвое вместо дорогого клиент обычно принимает,
 * обратное — нет. Справочник растёт по реальному спросу (ручные добавления
 * менеджера и согласованные клиентами замены), а не сплошной разметкой каталога.
 *
 * @property int $id
 * @property int $from_product_id
 * @property int $to_product_id
 * @property LinkKind $kind
 * @property LinkSource $source
 * @property int $score
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $rejected_at
 * @property int|null $created_by
 */
class ProductSubstitution extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_product_id',
        'to_product_id',
        'kind',
        'source',
        'score',
        'note',
        'confirmed_at',
        'rejected_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => LinkKind::class,
            'source' => LinkSource::class,
            'score' => 'integer',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Связи, которые автоподбор имеет право предлагать клиентам.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at')->whereNull('rejected_at');
    }

    /**
     * Очередь еженедельного подтверждения менеджером.
     */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereNull('confirmed_at')->whereNull('rejected_at');
    }
}
