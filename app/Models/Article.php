<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Tags\HasTags;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Article extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ArticleFactory> */
    use HasFactory, HasTags;
    use \App\Traits\HasContentMedia;

    protected $fillable = [
        'title',
        'slug',
        'short_description',
        'detailed_description',
        'meta_title',
        'meta_description',
    ];


}
