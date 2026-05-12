<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ERP-промо: группа товаров, помеченная флагом «новинка», «бестселлер» или «ликвидация» в 1С.
 *
 * Синхронизируется через события promotion.created/updated/deleted (1С → Сайт).
 * На товаре (Product) флаги is_new / is_bestseller / is_liquidation пересчитываются
 * как `EXISTS()` по привязке к любой ErpPromotion соответствующего type.
 *
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpPromotion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpPromotion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpPromotion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpPromotion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpPromotion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpPromotion whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpPromotion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpPromotion whereUuid($value)
 *
 * @mixin \Eloquent
 */
class ErpPromotion extends Model
{
    use HasFactory;

    public const TYPE_NEW = 'new';

    public const TYPE_BESTSELLER = 'bestseller';

    public const TYPE_LIQUIDATION = 'liquidation';

    public const TYPES = [self::TYPE_NEW, self::TYPE_BESTSELLER, self::TYPE_LIQUIDATION];

    /**
     * Соответствие между type и колонкой продукта, в которой хранится агрегированный флаг.
     */
    public const PRODUCT_FLAG_MAP = [
        self::TYPE_NEW => 'is_new',
        self::TYPE_BESTSELLER => 'is_bestseller',
        self::TYPE_LIQUIDATION => 'is_liquidation',
    ];

    protected $fillable = ['uuid', 'type'];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'erp_promotion_product');
    }
}
