<?php

namespace App\Models;

use App\Enums\InstructionAudience;
use App\Enums\InstructionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Инструкция для клиентов, менеджеров или склада.
 *
 * Ведётся в админке, читается в кабинете, CRM и WMS — по флагам аудитории.
 * Формат один из трёх ({@see InstructionType}): текст блоками (как новости),
 * PDF (коллекция `file`) или видео (ссылка `video_url` либо коллекция `video`).
 * Обложка — коллекция `cover`, необязательна.
 *
 * @property int $id
 * @property string $title
 * @property string|null $short_description
 * @property InstructionType $type
 * @property string|null $content
 * @property string|null $video_url
 * @property bool $for_clients
 * @property bool $for_crm
 * @property bool $for_wms
 * @property bool $is_published
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Instruction extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public const COLLECTION_COVER = 'cover';

    public const COLLECTION_FILE = 'file';

    public const COLLECTION_VIDEO = 'video';

    protected $fillable = [
        'title',
        'short_description',
        'type',
        'content',
        'video_url',
        'for_clients',
        'for_crm',
        'for_wms',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'type' => InstructionType::class,
            'for_clients' => 'boolean',
            'for_crm' => 'boolean',
            'for_wms' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION_COVER)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->singleFile();

        $this->addMediaCollection(self::COLLECTION_FILE)
            ->acceptsMimeTypes(['application/pdf'])
            ->singleFile();

        $this->addMediaCollection(self::COLLECTION_VIDEO)
            ->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime'])
            ->singleFile();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAudience(Builder $query, InstructionAudience $audience): Builder
    {
        return $query->where($audience->column(), true);
    }

    public function isFor(InstructionAudience $audience): bool
    {
        return (bool) $this->getAttribute($audience->column());
    }

    /**
     * @return list<InstructionAudience>
     */
    public function audiences(): array
    {
        return array_values(array_filter(
            InstructionAudience::cases(),
            fn (InstructionAudience $a) => $this->isFor($a),
        ));
    }

    /**
     * Инструкцию правили после создания — читателю это важнее даты создания.
     */
    public function wasUpdated(): bool
    {
        return $this->created_at !== null
            && $this->updated_at !== null
            && $this->updated_at->greaterThanOrEqualTo($this->created_at->copy()->addMinute());
    }
}
