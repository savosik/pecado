<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Tags\HasTags;

class News extends Model
{
    /** @use HasFactory<\Database\Factories\NewsFactory> */
    use HasFactory, HasTags;

    protected $fillable = [
        'title',
        'slug',
        'detailed_description',
        'meta_title',
        'meta_description',
    ];
}
