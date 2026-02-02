<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Certificate extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, Searchable;

    protected $fillable = [
        'external_id',
        'name',
        'type',
        'issued_at',
        'expires_at',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'expires_at' => 'date',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('files')
            ->acceptsMimeTypes([
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // docx
                'image/jpeg', // jpg
                'image/png', // png
                'application/pdf', // pdf
                'application/zip', // zip
                'text/plain', // txt
                'application/vnd.rar', // rar
                'application/x-rar-compressed', // rar alternative
                'application/x-rar', // rar alternative
            ]);
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $array = $this->toArray();

        $array['files'] = $this->media->map(function ($media) {
            return [
                'name' => $media->name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'custom_properties' => $media->custom_properties,
            ];
        })->toArray();

        // Convert date to timestamp for better Meilisearch handling if needed,
        // or keep as string. Meilisearch handles ISO8601 well.
        
        return $array;
    }

    /**
     * Get the products for this certificate.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_certificate');
    }
}
