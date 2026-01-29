<?php

namespace App\Models;

use App\Enums\BrandCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Tags\HasTags;

class Brand extends Model
{
    use HasFactory, HasTags;

    protected $fillable = [
        'name',
        'slug',
        'short_description',
        'category',
        'meta_title',
        'meta_description',
        'parent_id',
    ];

    protected $casts = [
        'category' => BrandCategory::class,
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Brand::class, 'parent_id');
    }

    public function story(): HasOne
    {
        return $this->hasOne(BrandStory::class);
    }
}
