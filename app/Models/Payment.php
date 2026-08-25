<?php

namespace App\Models;

use App\Models\Concerns\FiltersClientDocuments;
use App\Models\Concerns\HasCrmAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/**
 * Платёжный документ из 1С: поступление оплаты от клиента или возврат клиенту.
 *
 * Мастер данных — 1С. На сайте редактируются только `comment` и вложения;
 * остальные поля перезаписываются каждым `payment.updated`.
 *
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property \Illuminate\Support\Carbon $date
 * @property string $direction
 * @property string|null $operation_code
 * @property string|null $operation_name
 * @property string|null $document_type
 * @property int|null $user_id
 * @property int|null $company_id
 * @property string|null $contractor_uuid
 * @property string|null $tax_id
 * @property int|null $organization_id
 * @property string|null $organization_account
 * @property string|null $organization_bank_name
 * @property string|null $payer_account
 * @property string|null $payer_bank_name
 * @property string|null $bank_number
 * @property \Illuminate\Support\Carbon|null $bank_date
 * @property bool $bank_confirmed
 * @property \Illuminate\Support\Carbon|null $bank_confirmed_at
 * @property string|null $uip
 * @property string|null $purpose
 * @property numeric $amount
 * @property string|null $currency_code
 * @property string|null $comment
 * @property \Carbon\Carbon|null $erp_created_at
 * @property \Carbon\Carbon|null $erp_updated_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\Organization|null $organization
 * @property-read string $direction_label
 *
 * @method static \Database\Factories\PaymentFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class Payment extends Model implements HasMedia
{
    use FiltersClientDocuments, HasCrmAttachments, HasFactory, SoftDeletes;

    /** Поступление оплаты от клиента. */
    public const DIRECTION_IN = 'in';

    /** Возврат оплаты клиенту. */
    public const DIRECTION_OUT = 'out';

    /**
     * Погрешность сравнения денег. Копейка — минимальная значимая единица,
     * всё, что меньше, — артефакт округления decimal.
     */
    public const EPSILON = 0.01;

    protected $fillable = [
        'uuid',
        'number',
        'date',
        'direction',
        'operation_code',
        'operation_name',
        'document_type',
        'user_id',
        'company_id',
        'contractor_uuid',
        'tax_id',
        'organization_id',
        'organization_account',
        'organization_bank_name',
        'payer_account',
        'payer_bank_name',
        'bank_number',
        'bank_date',
        'bank_confirmed',
        'bank_confirmed_at',
        'uip',
        'purpose',
        // v15.16.0: комментарий из 1С. Отдельно от `comment`, который ведёт
        // сотрудник сайта и который 1С не перезаписывает
        'erp_comment',
        'amount',
        'currency_code',
        'comment',
        'erp_created_at',
        'erp_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'bank_date' => 'date',
            'bank_confirmed' => 'boolean',
            'bank_confirmed_at' => 'datetime',
            'amount' => 'decimal:2',
            'erp_created_at' => \App\Casts\ErpDatetime::class,
            'erp_updated_at' => \App\Casts\ErpDatetime::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (empty($payment->uuid)) {
                $payment->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Наша организация — получатель платежа.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIncoming(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_IN);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOutgoing(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUT);
    }

    public function getDirectionLabelAttribute(): string
    {
        return match ($this->direction) {
            self::DIRECTION_OUT => 'Возврат клиенту',
            default => 'Поступление',
        };
    }
}
