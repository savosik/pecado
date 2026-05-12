<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property array<array-key, mixed>|null $business_type
 * @property string|null $business_name
 * @property string|null $website_url
 * @property string|null $years_in_business
 * @property string|null $monthly_order_volume
 * @property bool $has_physical_store
 * @property string|null $store_count
 * @property array<array-key, mixed>|null $product_categories
 * @property string|null $how_found_us
 * @property string|null $additional_info
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereAdditionalInfo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereBusinessName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereBusinessType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereHasPhysicalStore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereHowFoundUs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereMonthlyOrderVolume($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereProductCategories($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereStoreCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereWebsiteUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserQuestionnaire whereYearsInBusiness($value)
 *
 * @mixin \Eloquent
 */
class UserQuestionnaire extends Model
{
    protected $fillable = [
        'user_id',
        'business_type',
        'business_name',
        'website_url',
        'years_in_business',
        'monthly_order_volume',
        'has_physical_store',
        'store_count',
        'product_categories',
        'how_found_us',
        'additional_info',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_type' => 'array',
            'product_categories' => 'array',
            'has_physical_store' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the questionnaire.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the questionnaire is completed.
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
