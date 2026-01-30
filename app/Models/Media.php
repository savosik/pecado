<?php

namespace App\Models;

use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Spatie\Tags\HasTags;

class Media extends SpatieMedia
{
    use Searchable, HasTags;

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'collection_name' => $this->collection_name,
            'custom_properties' => $this->custom_properties,
            'tags' => $this->tags->pluck('name')->toArray(),
        ];
    }
}
