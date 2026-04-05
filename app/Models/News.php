<?php

namespace App\Models;

use App\Helpers\SearchHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use Spatie\Tags\HasTags;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class News extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\NewsFactory> */
    use HasFactory, HasTags, Searchable;
    use \App\Traits\HasContentMedia;

    protected $fillable = [
        'title',
        'slug',
        'detailed_description',
        'is_published',
        'published_at',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $title = $this->title ?? '';

        return [
            'id' => (int) $this->id,
            'title' => $title,
            'title_translit' => SearchHelper::transliterate($title),
            'title_cyrillic' => SearchHelper::transliterateToCyrillic($title),
            'title_layout' => SearchHelper::convertLayout($title),
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->is_published && (is_null($this->published_at) || $this->published_at->lte(now()));
    }

    /**
     * Scope: только опубликованные новости.
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true)
                     ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }
}
