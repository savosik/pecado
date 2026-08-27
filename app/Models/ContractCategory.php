<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Категория реестра договоров — вкладка таблицы менеджеров.
 *
 * Не FK на organizations: вкладки не совпадают с юрлицами из 1С, и РОП должен
 * уметь завести новую сам. Организация — необязательная подсказка.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int|null $organization_id
 * @property int $sort_order
 * @property bool $is_active
 * @property-read Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Contract> $contracts
 *
 * @method static \Database\Factories\ContractCategoryFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class ContractCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'organization_id',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'category_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
