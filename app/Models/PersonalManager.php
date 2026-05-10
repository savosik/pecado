<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PersonalManager extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'phone',
        'email',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            ])
            ->singleFile();
    }
}
