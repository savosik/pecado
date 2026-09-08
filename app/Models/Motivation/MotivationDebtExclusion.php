<?php

namespace App\Models\Motivation;

use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Задолженность, выведенная из базы начисления вычета К1.
 *
 * Списанный безнадёжным долг и долг, переданный в претензионную работу, не должны
 * участвовать в начислении: иначе показатель считается на задолженность, которой
 * работник уже не занимается.
 *
 * Возврат долга в базу оформляется закрытием исключения датой, а не удалением строки:
 * разбор прошлого расчёта не должен упираться в исчезнувшее основание.
 *
 * @property int $id
 * @property int|null $shipment_id
 * @property int $user_id
 * @property string $reason
 * @property \Illuminate\Support\Carbon $excluded_from
 * @property \Illuminate\Support\Carbon|null $excluded_until
 * @property string|null $amount
 * @property string|null $document_ref
 * @property string|null $comment
 * @property int|null $author_id
 */
class MotivationDebtExclusion extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationDebtExclusionFactory> */
    use HasFactory;

    public const REASON_WRITTEN_OFF = 'written_off';

    public const REASON_LEGAL = 'legal';

    public const REASON_DISPUTED = 'disputed';

    public const REASON_OTHER = 'other';

    protected $fillable = [
        'shipment_id',
        'user_id',
        'reason',
        'excluded_from',
        'excluded_until',
        'amount',
        'document_ref',
        'comment',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'excluded_from' => 'date',
            'excluded_until' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Исключения, действовавшие в указанный день.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveOn(Builder $query, Carbon $day): Builder
    {
        return $query
            ->whereDate('excluded_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('excluded_until')->orWhereDate('excluded_until', '>=', $day));
    }
}
