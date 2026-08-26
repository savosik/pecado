<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Срабатывание правила: какое правило какое письмо поймало.
 *
 * Нужен затем, что «правило не поймало ничего» — самый частый признак опечатки
 * в условии, и увидеть это менеджер должен в списке правил, а не по жалобе клиента.
 *
 * @property int $id
 * @property int $rule_id
 * @property int $crm_email_id
 * @property bool $auto_sent
 */
class CrmMailRuleHit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'rule_id',
        'crm_email_id',
        'auto_sent',
    ];

    protected function casts(): array
    {
        return [
            'auto_sent' => 'boolean',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CrmMailRule::class, 'rule_id');
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(CrmEmail::class, 'crm_email_id');
    }
}
