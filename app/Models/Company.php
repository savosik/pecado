<?php

namespace App\Models;

use App\Enums\Country;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $fillable = [
        'user_id',
        'country',
        'name',
        'legal_name',
        'tax_id',
        'registration_number',
        'tax_code',
        'okpo_code',
        'legal_address',
        'actual_address',
        'phone',
        'email',
        'erp_id',
    ];

    protected function casts(): array
    {
        return [
            'country' => Country::class,
        ];
    }

    /**
     * The event map for the model.
     *
     * @var array<string, string>
     */
    protected $dispatchesEvents = [
        'created' => \App\Events\CompanyCreated::class,
        'updated' => \App\Events\CompanyUpdated::class,
        'deleted' => \App\Events\CompanyDeleted::class,
    ];

    /**
     * Get the user that owns the company.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the bank accounts for the company.
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class);
    }

    /**
     * Get the primary bank account for the company.
     */
    public function primaryBankAccount(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class)->where('is_primary', true);
    }
}
