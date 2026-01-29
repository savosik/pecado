<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Tags\HasTags;

class Article extends Model
{
    /** @use HasFactory<\Database\Factories\ArticleFactory> */
    use HasFactory, HasTags;

    protected $fillable = [
        'title',
        'slug',
        'short_description',
        'detailed_description',
        'meta_title',
        'meta_description',
    ];
}
