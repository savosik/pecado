<?php

namespace App\Models;

use App\Enums\PromotionRuleMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Правило акции — механика конструктора промо.
 *
 * Условие (`conditions`) описывает порог по количеству штук или сумме корзины,
 * награда (`rewards`) — промо-позицию с произвольной ценой. Контентная страница акции
 * (`Promotion`) необязательна: служебное правило живёт с `promotion_id = NULL`.
 *
 * Структуру JSON-полей проверяет App\Services\Promotion\PromotionRuleSchemaValidator,
 * список товаров-участников материализует RecalculatePromotionRuleProductsJob.
 *
 * @property int $id
 * @property int|null $promotion_id
 * @property string $name
 * @property bool $is_active
 * @property PromotionRuleMode $mode
 * @property \Illuminate\Support\Carbon|null $starts_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property int $priority
 * @property bool $stackable
 * @property array $conditions
 * @property array $rewards
 * @property array|null $audience
 * @property array|null $limits
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Promotion|null $promotion
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Database\Factories\PromotionRuleFactory factory($count = null, $state = [])
 * @method static Builder<static>|PromotionRule active(?\DateTimeInterface $at = null)
 * @method static Builder<static>|PromotionRule forMode(PromotionRuleMode|string $mode)
 *
 * @mixin \Eloquent
 */
class PromotionRule extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Товар участвует в условии срабатывания. */
    public const ROLE_CONDITION = 'condition';

    /** Товар выдаётся как награда. */
    public const ROLE_REWARD = 'reward';

    public const ROLES = [self::ROLE_CONDITION, self::ROLE_REWARD];

    /** Награда — конкретный товар. */
    public const REWARD_TYPE_FIXED = 'fixed';

    /** Награда — выбор клиента из нескольких товаров. */
    public const REWARD_TYPE_CHOICE = 'choice';

    /** Награда выдаётся один раз при достижении порога. */
    public const MULTIPLY_ONCE = 'once';

    /** Награда выдаётся на каждые N штук / X рублей, с обязательным потолком. */
    public const MULTIPLY_PER_THRESHOLD = 'per_threshold';

    /** Порог по количеству штук. */
    public const AGGREGATE_QUANTITY = 'quantity';

    /** Порог по сумме в рублях. */
    public const AGGREGATE_AMOUNT = 'amount';

    /**
     * Единственная поддерживаемая база цены: финальная цена клиента
     * (индивидуальная цена со скидкой).
     */
    public const PRICE_BASIS_CLIENT_FINAL = 'client_final';

    /** Канал «сайт»: корзина и чекаут. */
    public const CHANNEL_SITE = 'site';

    /** Канал «api»: клиентское API. */
    public const CHANNEL_API = 'api';

    public const CHANNELS = [self::CHANNEL_SITE, self::CHANNEL_API];

    protected $fillable = [
        'promotion_id',
        'name',
        'is_active',
        'mode',
        'starts_at',
        'ends_at',
        'priority',
        'stackable',
        'conditions',
        'rewards',
        'audience',
        'limits',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'stackable' => 'boolean',
            'mode' => PromotionRuleMode::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
            'conditions' => 'array',
            'rewards' => 'array',
            'audience' => 'array',
            'limits' => 'array',
        ];
    }

    protected $attributes = [
        'mode' => PromotionRuleMode::INFO->value,
        'is_active' => false,
        'stackable' => true,
        'priority' => 0,
    ];

    /**
     * Акция-лендинг. NULL — служебное правило без страницы.
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * Материализованные товары-участники (условие + награды).
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_rule_product')
            ->withPivot('role');
    }

    /**
     * Товары, участвующие в условии срабатывания.
     */
    public function conditionProducts(): BelongsToMany
    {
        return $this->products()->wherePivot('role', self::ROLE_CONDITION);
    }

    /**
     * Товары, выдаваемые как награда.
     */
    public function rewardProducts(): BelongsToMany
    {
        return $this->products()->wherePivot('role', self::ROLE_REWARD);
    }

    /**
     * Правило включено и момент $at попадает в период действия.
     * Границы периода включительные.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $moment = $at ?? now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $moment))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $moment));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForMode(Builder $query, PromotionRuleMode|string $mode): Builder
    {
        return $query->where('mode', $mode instanceof PromotionRuleMode ? $mode->value : $mode);
    }

    /**
     * Работает ли правило в указанном канале.
     * Пустой список каналов или его отсутствие — правило работает везде.
     */
    public function appliesToChannel(string $channel): bool
    {
        $channels = $this->audience['channels'] ?? [];

        if (! is_array($channels) || $channels === []) {
            return true;
        }

        return in_array($channel, $channels, true);
    }

    /**
     * Действует ли правило в указанный момент (включая границы периода).
     */
    public function isActiveAt(?\DateTimeInterface $at = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $moment = $at ? \Illuminate\Support\Carbon::parse($at) : now();

        if ($this->starts_at && $this->starts_at->greaterThan($moment)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->lessThan($moment)) {
            return false;
        }

        return true;
    }
}
