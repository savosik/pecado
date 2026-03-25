<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agreement extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'partner_uuid',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function discounts(): HasMany
    {
        return $this->hasMany(AgreementDiscount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_uuid', 'erp_id');
    }
}
