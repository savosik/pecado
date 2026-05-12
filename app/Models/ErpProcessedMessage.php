<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $message_id
 * @property string $event
 * @property \Illuminate\Support\Carbon $processed_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpProcessedMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpProcessedMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpProcessedMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpProcessedMessage whereEvent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpProcessedMessage whereMessageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpProcessedMessage whereProcessedAt($value)
 *
 * @mixin \Eloquent
 */
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
