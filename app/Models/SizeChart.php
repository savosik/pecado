<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SizeChart extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'values',
    ];

    protected $casts = [
        'values' => 'array',
    ];

    public function brands(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Brand::class);
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Product::class);
    }
}
