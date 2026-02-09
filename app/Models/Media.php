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
        $ownerData = $this->getOwnerSearchData();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'mime_type_group' => $this->getMimeTypeGroup(),
            'collection_name' => $this->collection_name,
            'model_type' => $this->model_type,
            'tags' => $this->tags->pluck('name')->toArray(),
            // Данные владельца для поиска по связанным сущностям
            'owner_name' => $ownerData['name'] ?? '',
            'owner_code' => $ownerData['code'] ?? '',
            'owner_sku' => $ownerData['sku'] ?? '',
            'owner_brand' => $ownerData['brand'] ?? '',
            'owner_category' => $ownerData['category'] ?? '',
            'owner_description' => $ownerData['description'] ?? '',
        ];
    }

    /**
     * Получить поисковые данные из родительской сущности (владельца медиафайла).
     *
     * @return array<string, string>
     */
    protected function getOwnerSearchData(): array
    {
        $model = $this->model;

        if (!$model) {
            return [];
        }

        $data = [];

        // Название: title или name
        $data['name'] = $model->title ?? $model->name ?? '';

        // Описание
        $data['description'] = $model->short_description
            ?? $model->description
            ?? $model->detailed_description
            ?? '';

        // Специфичные поля для Product
        if ($model instanceof Product) {
            $data['code'] = $model->code ?? '';
            $data['sku'] = $model->sku ?? '';
            $data['brand'] = $model->brand?->name ?? '';
            $data['category'] = $model->categories?->pluck('name')->implode(', ') ?? '';
        }

        // Для StorySlide — подтянуть название сториса
        if ($model instanceof StorySlide) {
            $storyName = $model->story?->name ?? '';
            $data['name'] = trim($storyName . ' ' . ($model->title ?? ''));
            $data['description'] = $model->content ?? '';
        }

        // Для BrandStory — подтянуть название бренда
        if ($model instanceof BrandStory) {
            $data['brand'] = $model->brand?->name ?? '';
        }

        return $data;
    }

    /**
     * Определить группу MIME-типа для фильтрации в Meilisearch.
     */
    protected function getMimeTypeGroup(): string
    {
        $mime = $this->mime_type ?? '';

        if (str_starts_with($mime, 'image/')) return 'images';
        if (str_starts_with($mime, 'video/')) return 'videos';
        if (str_starts_with($mime, 'application/')) return 'documents';

        return 'other';
    }
}
