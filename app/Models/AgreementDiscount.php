<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgreementDiscount extends Model
{
    use HasFactory;

    protected $fillable = [
        'agreement_id',
        'discount_uuid',
        'name',
        'percentage',
        'product_segment_uuid',
    ];

    protected $casts = [
        'percentage' => 'float',
    ];

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function productSegment(): BelongsTo
    {
        return $this->belongsTo(ProductSegment::class, 'product_segment_uuid', 'uuid');
    }
}
