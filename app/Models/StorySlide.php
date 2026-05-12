<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int $story_id
 * @property string|null $title
 * @property string|null $content
 * @property string|null $button_text
 * @property string|null $button_url
 * @property string|null $linkable_type
 * @property int|null $linkable_id
 * @property int $duration
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Model|\Eloquent|null $linkable
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read \App\Models\Story $story
 *
 * @method static \Database\Factories\StorySlideFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereButtonText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereButtonUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereDuration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereLinkableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereLinkableType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereStoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StorySlide whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class StorySlide extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'story_id',
        'title',
        'content',
        'button_text',
        'button_url',
        'linkable_type',
        'linkable_id',
        'duration',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Сторис, которому принадлежит слайд
     */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /**
     * Полиморфная связь - сущность, на которую ссылается слайд
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('default')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
                'video/mp4', 'video/webm', 'video/quicktime',
            ])
            ->singleFile();
    }
}
