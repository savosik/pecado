<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Аватарка партнёра в CRM.
 *
 * Запись живёт и без файла: неудачные попытки генерации тоже надо где-то
 * считать, иначе очередь ходила бы к платному API по кругу.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $path
 * @property string|null $disk
 * @property string|null $source
 * @property int|null $size
 * @property string|null $mime
 * @property string|null $checksum
 * @property string|null $prompt
 * @property string|null $image_model
 * @property string|null $text_model
 * @property int|null $uploaded_by
 * @property \Illuminate\Support\Carbon|null $generated_at
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property string|null $failure_reason
 */
class CrmClientAvatar extends Model
{
    /** @use HasFactory<\Database\Factories\CrmClientAvatarFactory> */
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AI = 'ai';

    protected $fillable = [
        'user_id',
        'path',
        'disk',
        'source',
        'size',
        'mime',
        'checksum',
        'prompt',
        'image_model',
        'text_model',
        'uploaded_by',
        'generated_at',
        'attempts',
        'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function hasFile(): bool
    {
        return $this->path !== null;
    }

    /**
     * Загруженную менеджером ИИ не перерисовывает: ручной выбор старше любого
     * автоматического.
     */
    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }
}
