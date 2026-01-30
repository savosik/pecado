<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Tags\HasTags;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class News extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\NewsFactory> */
    use HasFactory, HasTags;
    use \App\Traits\HasContentMedia;

    protected $fillable = [
        'title',
        'slug',
        'detailed_description',
        'meta_title',
        'meta_description',
    ];


}
