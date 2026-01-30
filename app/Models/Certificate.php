<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'name',
        'type',
        'issued_at',
        'files',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'files' => 'array',
    ];
}
