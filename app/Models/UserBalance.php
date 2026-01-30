<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBalance extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'user_id',
        'currency_id',
        'balance',
        'overdue_debt',
    ];

    /**
     * Get the user that owns the balance.
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the currency of the balance.
     */
    public function currency(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
