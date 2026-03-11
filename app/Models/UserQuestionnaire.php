<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
