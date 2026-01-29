<?php

namespace App\Models;

use App\Models\Scopes\DeliveryAddressScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAddress extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'address',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new DeliveryAddressScope);
    }

    /**
     * Get the user that owns the delivery address.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
