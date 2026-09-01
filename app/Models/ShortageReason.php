<?php

namespace App\Models;

use App\Enums\Shortage\ShortageReasonCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Причина недобора — строка справочника, который ведёт руководитель отдела.
 *
 * Раньше причин было две и они жили в перечислении кода: «склад» и «клиент».
 * Отдел работает с девятью, и список будет меняться дальше — заводить строку
 * должен РОП, а не разработчик. Категория (`category`) остаётся перечислением:
 * см. {@see ShortageReasonCategory}.
 *
 * Заводские причины (`is_system`) удалить нельзя: на них ссылается разметка
 * прошлых периодов и перенос старых меток. Отключаются они как любые другие —
 * неактивная причина исчезает из выпадающего списка, но остаётся в сводках.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property ShortageReasonCategory $category
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_system
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderItem> $orderItems
 *
 * @method static \Database\Factories\ShortageReasonFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class ShortageReason extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => ShortageReasonCategory::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'cancel_reason_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Порядок справочника: сначала категория, внутри — ручной порядок РОПа.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function color(): string
    {
        return $this->category->color();
    }

    /**
     * @return array<string, mixed>
     */
    public function toOption(): array
    {
        return [
            'value' => $this->getKey(),
            'label' => $this->name,
            'description' => $this->description,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'color' => $this->color(),
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
        ];
    }
}
