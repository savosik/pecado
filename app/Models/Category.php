<?php

namespace App\Models;

use App\Helpers\SearchHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\NodeTrait;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Tags\HasTags;

/**
 * @property int $id
 * @property int $_lft
 * @property int $_rgt
 * @property int|null $parent_id
 * @property string|null $external_id
 * @property string|null $uuid UUID из 1С (US-13)
 * @property string $name
 * @property int $is_group Признак узла-группы из 1С (US-13)
 * @property bool $is_active Флаг активности категории. Неактивные категории и их товары не отображаются на сайте.
 * @property string|null $slug
 * @property string|null $short_description
 * @property string|null $description
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Attribute> $attributes
 * @property-read int|null $attributes_count
 * @property-read \Kalnoy\Nestedset\Collection<int, Category> $children
 * @property-read int|null $children_count
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read Category|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @property \Illuminate\Database\Eloquent\Collection<int, \Spatie\Tags\Tag> $tags
 * @property-read int|null $tags_count
 *
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category active()
 * @method static \Kalnoy\Nestedset\Collection<int, static> all($columns = ['*'])
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category ancestorsAndSelf($id, array $columns = [])
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category ancestorsOf($id, array $columns = [])
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category applyNestedSetScope(?string $table = null)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category countErrors()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category d()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category defaultOrder(string $dir = 'asc')
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category descendantsAndSelf($id, array $columns = [])
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category descendantsOf($id, array $columns = [], $andSelf = false)
 * @method static \Database\Factories\CategoryFactory factory($count = null, $state = [])
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category fixSubtree($root)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category fixTree($root = null)
 * @method static \Kalnoy\Nestedset\Collection<int, static> get($columns = ['*'])
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category getNodeData($id, $required = false)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category getPlainNodeData($id, $required = false)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category getTotalErrors()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category hasChildren()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category hasParent()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category isBroken()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category leaves(array $columns = [])
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category makeGap(int $cut, int $height)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category moveNode($key, $position)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category newModelQuery()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category newQuery()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category orWhereAncestorOf(bool $id, bool $andSelf = false)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category orWhereDescendantOf($id)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category orWhereNodeBetween($values)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category orWhereNotDescendantOf($id)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category query()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category rebuildSubtree($root, array $data, $delete = false)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category rebuildTree(array $data, $delete = false, $root = null)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category reversed()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category root(array $columns = [])
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereAncestorOf($id, $andSelf = false, $boolean = 'and')
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereAncestorOrSelf($id)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereCreatedAt($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereDescendantOf($id, $boolean = 'and', $not = false, $andSelf = false)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereDescendantOrSelf(string $id, string $boolean = 'and', string $not = false)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereDescription($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereExternalId($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereId($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereIsActive($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereIsAfter($id, $boolean = 'and')
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereIsBefore($id, $boolean = 'and')
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereIsGroup($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereIsLeaf()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereIsRoot()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereLft($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereMetaDescription($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereMetaTitle($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereName($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereNodeBetween($values, $boolean = 'and', $not = false, $query = null)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereNotDescendantOf($id)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereParentId($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereRgt($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereShortDescription($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereSlug($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereUpdatedAt($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category whereUuid($value)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category withAllTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category withAllTagsOfAnyType($tags)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category withAnyTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category withAnyTagsOfAnyType($tags)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category withAnyTagsOfType(array|string $type)
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category withDepth(string $as = 'depth')
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category withoutRoot()
 * @method static \Kalnoy\Nestedset\QueryBuilder<static>|Category withoutTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 *
 * @mixin \Eloquent
 */
class Category extends Model implements HasMedia
{
    use HasFactory;
    use HasTags;
    use InteractsWithMedia;
    use NodeTrait;
    use Searchable {
        Searchable::usesSoftDelete insteadof NodeTrait;
    }

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'external_id',
        'uuid',
        'is_group',
        'is_active',
        'sort',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * Scope: только активные категории.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the products for this category.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get the attributes associated with this category.
     */
    public function attributes(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Attribute::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('icon')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
            ])
            ->singleFile();
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $name = $this->name ?? '';

        return [
            'id' => (int) $this->id,
            'name' => $name,
            'name_translit' => SearchHelper::transliterate($name),
            'name_cyrillic' => SearchHelper::transliterateToCyrillic($name),
            'name_layout' => SearchHelper::convertLayout($name),
        ];
    }
}
