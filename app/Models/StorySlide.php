<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StorySlide extends Model
{
    use HasFactory;

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

    protected $casts = [
        'duration' => 'integer',
        'sort_order' => 'integer',
    ];

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
}
