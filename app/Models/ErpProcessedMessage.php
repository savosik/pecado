<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErpProcessedMessage extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'message_id';

    protected $keyType = 'string';

    protected $fillable = [
        'message_id',
        'event',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
