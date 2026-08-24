<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * Подписчики правила, ещё не сохранённого в базу.
     *
     * @var list<int>|null
     */
    private ?array $transientClientIds = null;

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
     * Партнёры, подписанные на правило. Пустой список означает «все».
     *
     * @return BelongsToMany<User, $this>
     */
    public function clients(): BelongsToMany
    {
        // Без withTimestamps(): в сводной таблице есть только created_at,
        // а хелпер требует обе колонки. Дату проставляем при подписке.
        return $this->belongsToMany(User::class, 'crm_mail_rule_clients', 'rule_id', 'client_user_id')
            ->withPivot('created_by_user_id', 'created_at');
    }

    /**
     * Кого правило подписало — списком идентификаторов.
     *
     * @return list<int>
     */
    public function subscribedClientIds(): array
    {
        if ($this->transientClientIds !== null) {
            return $this->transientClientIds;
        }

        if (! $this->exists) {
            return [];
        }

        return $this->clients->map(fn (User $client): int => (int) $client->getKey())->values()->all();
    }

    /**
     * Подставить список подписчиков непривязанному правилу.
     *
     * Нужно превью: там правило существует только в форме, строки в базе
     * ещё нет, а показать надо ровно то, что поймает сохранённое правило.
     *
     * @param  list<int>  $ids
     */
    public function withSubscribedClientIds(array $ids): static
    {
        $this->transientClientIds = array_values(array_unique(array_map('intval', $ids)));

        return $this;
    }

    /**
     * Попадает ли письмо этого партнёра под адресную часть правила.
     *
     * Пустой список — все партнёры, включая письма без партнёра вовсе
     * (внутренние сводки, вопросы с сайта). Как только список заполнен,
     * правило становится адресным: письмо без партнёра под него не подходит,
     * потому что «подписаны эти трое» и «подписаны все» — разные намерения.
     */
    public function appliesToClient(?int $clientUserId): bool
    {
        $subscribed = $this->subscribedClientIds();

        if ($subscribed === []) {
            return true;
        }

        if ($clientUserId === null) {
            return false;
        }

        return in_array($clientUserId, $subscribed, true);
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
