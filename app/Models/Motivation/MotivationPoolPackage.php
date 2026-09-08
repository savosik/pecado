<?php

namespace App\Models\Motivation;

use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Пакет партнёров, выданный работнику из Пула.
 *
 * Пул холодный: из 618 ничейных партнёров отгружались когда-либо четверо. Поэтому
 * раздача идёт пакетами со сроками — не связался в срок, партнёр возвращается в Пул, —
 * а не выгрузкой всей базы разом.
 *
 * @property int $id
 * @property int $personal_manager_id
 * @property \Illuminate\Support\Carbon $issued_on
 * @property int|null $issued_by
 * @property \Illuminate\Support\Carbon $contact_due_on
 * @property \Illuminate\Support\Carbon $shipment_due_on
 * @property string $status
 * @property string|null $comment
 */
class MotivationPoolPackage extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationPoolPackageFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_BLOCKED_BY_TAP = 'blocked_by_tap';

    protected $fillable = [
        'personal_manager_id',
        'issued_on',
        'issued_by',
        'contact_due_on',
        'shipment_due_on',
        'status',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'contact_due_on' => 'date',
            'shipment_due_on' => 'date',
        ];
    }

    /** @return BelongsTo<PersonalManager, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return HasMany<MotivationPoolPackageItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MotivationPoolPackageItem::class, 'package_id');
    }
}
