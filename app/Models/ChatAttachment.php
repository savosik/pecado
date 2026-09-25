<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Файл, прикреплённый клиентом к ходу чата-помощника.
 *
 * Оригинал лежит в хранилище (MinIO); изображения и PDF уходят модели блоками
 * запроса, таблицы — через Files API Anthropic и выполнение кода. `kind`
 * определяется по MIME из config/assistant.php, а не по расширению.
 *
 * @property int $id
 * @property int $thread_id
 * @property int|null $message_id
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $kind
 * @property string|null $anthropic_file_id
 * @property-read ChatThread $thread
 * @property-read ChatMessage|null $message
 */
class ChatAttachment extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_DOCUMENT = 'document';

    public const KIND_TABLE = 'table';

    public const KIND_TEXT = 'text';

    protected $fillable = [
        'thread_id',
        'message_id',
        'user_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'kind',
        'anthropic_file_id',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    /**
     * Представление для фронта: без пути в хранилище.
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'kind' => $this->kind,
        ];
    }
}
