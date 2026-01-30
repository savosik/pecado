<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'barcode',
    ];

    /**
     * Get the product that owns this barcode.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
