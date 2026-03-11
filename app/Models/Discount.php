<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'percentage',
        'external_id',
        'is_posted',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'percentage' => 'float',
        'is_posted' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'discount_user');
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'discount_product');
    }

    /**
     * US-03 v2: Сегменты номенклатуры (US-11), привязанные к скидке.
     */
    public function productSegments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ProductSegment::class, 'discount_product_segment');
    }

    /**
     * US-03 v2: Сегменты партнёров (US-12), привязанные к скидке.
     */
    public function partnerSegments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(PartnerSegment::class, 'discount_partner_segment');
    }
}
