<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'attribute_id',
        'value',
        'value_hash',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if ($model->isDirty('value') && ! $model->isDirty('value_hash')) {
                $model->value_hash = self::hashValue((string) $model->value);
            }
        });
    }

    public static function hashValue(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Get the attribute that owns this value.
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
