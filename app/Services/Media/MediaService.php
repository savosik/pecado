<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\FileAdder;

class MediaService
{
    /**
     * Upload a file to the media library, optionally attaching it to a model.
     *
     * @param UploadedFile $file
     * @param string $collection
     * @param Model|null $model
     * @param array $properties
     * @return Media
     */
    public function upload(
        UploadedFile $file,
        string $collection = 'default',
        ?Model $model = null,
        array $properties = []
    ): Media {
        /** @var FileAdder $fileAdder */
        if ($model) {
            $fileAdder = $model->addMedia($file);
        } else {
            // If no model is provided, we might want to attach it to a "Ghost" model or handling unattached media.
            // However, Spatie Media Library primarily works with models.
            // Common pattern: Use a specific 'MediaStorage' model or similar, OR just return the file adder to be processed.
            // But here, let's assume usage WITH a model is the primary use case, 
            // OR we use the Media model itself if we are just storing it "floating".
            // Since Spatie requires a model to attach to for `addMedia`, 
            // we will require a model for now, or the caller handles it.
            // Alternatively, creating a "TemporaryUpload" model is a strategy.
            
            // For now, let's strictly require a model OR implement a "Floating" upload handling if needed.
            // But based on requirements "Unified media storage", let's support "Unattached" uploads if possible.
            // Spatie doesn't easily support "Model-less" media out of the box without a "Subject".
            // FOR NOW: Let's assume we always attach to *something* or we throw exception.
             throw new \InvalidArgumentException('Model is required for uploading media in this implementation.');
        }

        return $fileAdder
            ->withCustomProperties($properties)
            ->toMediaCollection($collection);
    }

    /**
     * Attach an existing media item to another model (cloning/moving).
     *
     * @param Media $media
     * @param Model $model
     * @param string $collection
     * @return Media
     */
    public function attach(Media $media, Model $model, string $collection = 'default'): Media
    {
        return $media->move($model, $collection);
    }
    
    /**
     * Copy media to another model.
     * 
     * @param Media $media
     * @param Model $model
     * @param string $collection
     * @return Media
     */
    public function copy(Media $media, Model $model, string $collection = 'default'): Media
    {
        return $media->copy($model, $collection);
    }

    /**
     * Delete media.
     *
     * @param Media $media
     * @return bool|null
     */
    public function delete(Media $media): ?bool
    {
        return $media->delete();
    }
    
    /**
     * Sync tags for a media item.
     * 
     * @param Media $media
     * @param array $tags
     * @return Media
     */
    public function syncTags(Media $media, array $tags): Media
    {
        $media->syncTags($tags);
        return $media;
    }
}
