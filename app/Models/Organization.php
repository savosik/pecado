<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Наша организация (юрлицо компании), от имени которой 1С проводит документы.
 *
 * Справочник ведётся на сайте вручную: 1С в своих сообщениях присылает только UUID
 * организации, поэтому `external_id` заполняет админ — как у складов.
 *
 * @property int $id
 * @property string|null $external_id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $tax_id
 * @property string|null $tax_code
 * @property string|null $bank_name
 * @property string|null $bank_bik
 * @property string|null $correspondent_account
 * @property string|null $account_number
 * @property bool $is_active
 * @property bool $is_stub
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @method static \Database\Factories\OrganizationFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class Organization extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'external_id',
        'name',
        'legal_name',
        'tax_id',
        'tax_code',
        'bank_name',
        'bank_bik',
        'correspondent_account',
        'account_number',
        'is_active',
        'is_stub',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_stub' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Организации, используемые в текущих документах.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Заведённые вручную организации — без заглушек.
     *
     * Заглушку создаёт OrganizationResolver, когда 1С прислала UUID, которого в справочнике
     * ещё нет. Такую запись нельзя предлагать как обычную организацию: у неё вместо
     * названия UUID и нет реквизитов.
     */
    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_stub', false);
    }

    /**
     * Только заглушки — для счётчика в админке.
     */
    public function scopeStub(Builder $query): Builder
    {
        return $query->where('is_stub', true);
    }

    /**
     * Порядок вывода в списках и фильтрах.
     *
     * Заглушки идут первыми: это единственный сигнал админу, что 1С работает
     * с незаведённым юрлицом.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_stub')->orderBy('sort_order')->orderBy('name');
    }
}
