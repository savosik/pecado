<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property string|null $bank_name
 * @property string|null $bank_bik
 * @property string|null $correspondent_account
 * @property string $account_number
 * @property bool $is_primary
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Company|null $company
 *
 * @method static \Database\Factories\CompanyBankAccountFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount whereAccountNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount whereBankBik($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount whereBankName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount whereCorrespondentAccount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount whereIsPrimary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompanyBankAccount whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class CompanyBankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'bank_name',
        'bank_bik',
        'correspondent_account',
        'account_number',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * Get the company that owns the bank account.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
