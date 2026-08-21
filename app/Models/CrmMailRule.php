<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Правило-фильтр над потоком писем.
 *
 * Ровно то же, что фильтр в почтовом ящике: условия по меткам и тексту письма
 * плюс список получателей. Приоритетов и остановки разбора нет — правила
 * независимы, письмо может подойти под несколько, адрес не дублируется.
 *
 * @property int $id
 * @property string $name
 * @property int|null $user_id
 * @property array<string, mixed>|null $conditions
 * @property list<string> $recipients
 * @property list<string>|null $cc
 * @property bool $auto_send
 * @property bool $is_active
 * @property int|null $throttle_minutes
 * @property int $matched_count
 * @property \Illuminate\Support\Carbon|null $last_matched_at
 */
class CrmMailRule extends Model
{
    /** @use HasFactory<\Database\Factories\CrmMailRuleFactory> */
    use HasFactory;

    /** Получатель «тот же клиент, о котором письмо» — раскрывается по письму. */
    public const RECIPIENT_CLIENT = 'клиент';

    /** Получатель «персональный менеджер клиента» — раскрывается по письму. */
    public const RECIPIENT_MANAGER = 'менеджер';

    protected $fillable = [
        'name',
        'user_id',
        'conditions',
        'recipients',
        'cc',
        'auto_send',
        'is_active',
        'throttle_minutes',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'recipients' => 'array',
            'cc' => 'array',
            'auto_send' => 'boolean',
            'is_active' => 'boolean',
            'throttle_minutes' => 'integer',
            'matched_count' => 'integer',
            'last_matched_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(CrmMailRuleHit::class, 'rule_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
