<?php

namespace App\Models\Motivation;

use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Возражение работника по расчёту (п. 11.3).
 *
 * Сумму не меняет: адресуется руководителю, который либо переоткрывает месяц
 * новой версией, либо отвечает отказом с обоснованием.
 *
 * @property int $id
 * @property int $calculation_id
 * @property int $personal_manager_id
 * @property int|null $author_id
 * @property string $reason
 * @property string $status
 * @property string|null $response
 * @property int|null $responded_by
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class MotivationObjection extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationObjectionFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Ждёт ответа',
        self::STATUS_ACCEPTED => 'Принято',
        self::STATUS_REJECTED => 'Отклонено',
    ];

    protected $fillable = [
        'calculation_id',
        'personal_manager_id',
        'author_id',
        'reason',
        'status',
        'response',
        'responded_by',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PayrollCalculation, $this> */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(PayrollCalculation::class, 'calculation_id');
    }

    /** @return BelongsTo<PersonalManager, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
