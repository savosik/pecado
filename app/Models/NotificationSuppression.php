<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Стоп-лист адресов.
 *
 * scope разделяет транзакционное и рекламное: отписка от рассылок не должна
 * отключать уведомления о заказах, поэтому запись со scope='marketing'
 * гасит только домен campaigns.
 */
class NotificationSuppression extends Model
{
    use HasFactory;

    /** Не отправлять вообще ничего. */
    public const SCOPE_ALL = 'all';

    /** Не отправлять рекламу; транзакционные уведомления продолжают идти. */
    public const SCOPE_MARKETING = 'marketing';

    public const REASON_UNSUBSCRIBED = 'unsubscribed';

    public const REASON_BOUNCE = 'bounce';

    public const REASON_COMPLAINT = 'complaint';

    public const REASON_MANUAL = 'manual';

    protected $fillable = [
        'email',
        'scope',
        'reason',
        'contact_id',
        'user_id',
        'note',
        'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class, 'contact_id');
    }

    /**
     * Действующие запреты: истёкшие не мешают отправке.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Запрещён ли адрес для события этого домена.
     */
    public static function blocks(string $email, string $eventKey): bool
    {
        $domain = explode('.', $eventKey)[0];

        $scopes = [self::SCOPE_ALL, $eventKey];

        // Реклама гасится отдельной областью: клиент отписался от акций,
        // но уведомления о своих заказах получать продолжает.
        if ($domain === 'campaigns') {
            $scopes[] = self::SCOPE_MARKETING;
        }

        return static::query()
            ->active()
            ->where('email', mb_strtolower(trim($email)))
            ->whereIn('scope', $scopes)
            ->exists();
    }
}
