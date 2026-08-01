<?php

namespace App\Models;

use App\Enums\ReturnStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $erp_number Номер возврата в 1С
 * @property int $user_id
 * @property ReturnStatus $status
 * @property string|null $comment
 * @property string|null $admin_comment
 * @property numeric $total_amount
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReturnItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\User $user
 *
 * @method static \Database\Factories\ProductReturnFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereAdminComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereErpNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductReturn withoutTrashed()
 *
 * @mixin \Eloquent
 */
class ProductReturn extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'returns';

    protected $fillable = [
        'uuid',
        'erp_number',
        'user_id',
        'organization_id',
        'status',
        'comment',
        'admin_comment',
        'total_amount',
    ];

    protected $casts = [
        'status' => ReturnStatus::class,
        'total_amount' => 'decimal:2',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function ($return) {
            if (empty($return->uuid)) {
                $return->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the user that owns the return.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Организация возврата — справочно (v15.8.0).
     *
     * Выводится с реализаций-оснований при создании; если 1С прислала своё значение
     * в `return.updated`, оно приоритетнее. NULL, когда основания принадлежат разным
     * организациям — возврат при этом не дробится.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the items for the return.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    /**
     * Recalculate the total amount from items.
     */
    public function recalculateTotal(): void
    {
        $this->total_amount = $this->items()->sum('subtotal');
        $this->save();
    }
}
