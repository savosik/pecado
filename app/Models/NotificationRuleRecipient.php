<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Получатель правила маршрутизации.
 *
 * Вид адресата определяет, как он раскрывается в конкретный адрес.
 * Два ключевых: CONTACT — ссылка на карточку адресной книги (сменился
 * бухгалтер, правится одна запись), CONTACT_ROLE — «все бухгалтеры этого
 * контрагента», где нового сотрудника правило подхватывает само.
 *
 * @property int $notification_rule_id
 * @property string $kind
 * @property int|null $contact_id
 * @property string|null $value
 * @property string $copy_type
 * @property bool $is_fallback
 */
class NotificationRuleRecipient extends Model
{
    use HasFactory;

    /** Конкретный контакт адресной книги. */
    public const KIND_CONTACT = 'contact';

    /** Все активные контакты роли у контрагента события. */
    public const KIND_CONTACT_ROLE = 'contact_role';

    /** Произвольный адрес, набранный руками. */
    public const KIND_EMAIL = 'email';

    /** Email аккаунта партнёра, которому принадлежит событие. */
    public const KIND_CLIENT_USER = 'client_user';

    /** Email юрлица из карточки контрагента. */
    public const KIND_COMPANY_EMAIL = 'company_email';

    /** Персональный менеджер партнёра с учётом замещения на время отсутствия. */
    public const KIND_PERSONAL_MANAGER = 'personal_manager';

    /** Список адресов из настроек по ключу из белого списка. */
    public const KIND_CONFIG_LIST = 'config_list';

    /** Вычеркнуть адрес, добавленный правилами с большим приоритетом. */
    public const KIND_SUPPRESS = 'suppress';

    /**
     * @return array<int, string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_CONTACT,
            self::KIND_CONTACT_ROLE,
            self::KIND_EMAIL,
            self::KIND_CLIENT_USER,
            self::KIND_COMPANY_EMAIL,
            self::KIND_PERSONAL_MANAGER,
            self::KIND_CONFIG_LIST,
            self::KIND_SUPPRESS,
        ];
    }

    /**
     * Человекочитаемое название вида адресата — для конструктора и журнала.
     */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_CONTACT => 'Контакт из адресной книги',
            self::KIND_CONTACT_ROLE => 'Все контакты роли',
            self::KIND_EMAIL => 'Произвольный адрес',
            self::KIND_CLIENT_USER => 'Клиент (почта аккаунта)',
            self::KIND_COMPANY_EMAIL => 'Почта контрагента',
            self::KIND_PERSONAL_MANAGER => 'Персональный менеджер',
            self::KIND_CONFIG_LIST => 'Список адресов из настроек',
            self::KIND_SUPPRESS => 'Исключить адресата',
            default => $kind,
        };
    }

    protected $fillable = [
        'notification_rule_id',
        'kind',
        'contact_id',
        'value',
        'copy_type',
        'is_fallback',
    ];

    protected function casts(): array
    {
        return ['is_fallback' => 'boolean'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'notification_rule_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class, 'contact_id');
    }

    /**
     * Токен персональной отписки: создаётся лениво, при первой отправке.
     *
     * Заранее его заводить незачем — большинство получателей правила письма
     * так и не получат (условия не совпадут).
     */
    public function ensureUnsubscribeToken(): string
    {
        if (blank($this->unsubscribe_token)) {
            $this->forceFill(['unsubscribe_token' => Str::random(64)])->saveQuietly();
        }

        return (string) $this->unsubscribe_token;
    }
}
