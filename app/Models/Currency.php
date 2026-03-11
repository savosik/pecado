<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'is_base',
        'official_rate',
        'rate_coefficient',
        'exchange_rate',
        'exchange_rate_date',
    ];

    protected $casts = [
        'is_base' => 'boolean',
        'official_rate' => 'decimal:10',
        'rate_coefficient' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'exchange_rate_date' => 'date',
    ];
}
