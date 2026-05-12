<?php

namespace App\Models;

use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Spatie\Tags\HasTags;

/**
 * @property int $id
 * @property string $model_type
 * @property int $model_id
 * @property string|null $uuid
 * @property string $collection_name
 * @property string $name
 * @property string $file_name
 * @property string|null $mime_type
 * @property string $disk
 * @property string|null $conversions_disk
 * @property int $size
 * @property array<array-key, mixed> $manipulations
 * @property array<array-key, mixed> $custom_properties
 * @property array<array-key, mixed> $generated_conversions
 * @property array<array-key, mixed> $responsive_images
 * @property int|null $order_column
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read mixed $extension
 * @property-read mixed $human_readable_size
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent $model
 * @property-read mixed $original_url
 * @property-read mixed $preview_url
 * @property \Illuminate\Database\Eloquent\Collection<int, \Spatie\Tags\Tag> $tags
 * @property-read int|null $tags_count
 * @property-read mixed $type
 *
 * @method static \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, static> all($columns = ['*'])
 * @method static \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, static> get($columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media newQuery()
 * @method static Builder<static>|Media ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereCollectionName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereConversionsDisk($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereCustomProperties($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereDisk($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereFileName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereGeneratedConversions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereManipulations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereModelId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereModelType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereOrderColumn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereResponsiveImages($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media withAllTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media withAllTagsOfAnyType($tags)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media withAnyTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media withAnyTagsOfAnyType($tags)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media withAnyTagsOfType(array|string $type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media withoutTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 *
 * @mixin \Eloquent
 */
class Media extends SpatieMedia
{
    use HasTags, Searchable;

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

        if (! $model) {
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
            $data['name'] = trim($storyName.' '.($model->title ?? ''));
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

        if (str_starts_with($mime, 'image/')) {
            return 'images';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'videos';
        }
        if (str_starts_with($mime, 'application/')) {
            return 'documents';
        }

        return 'other';
    }
}
