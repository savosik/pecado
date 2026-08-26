<?php

namespace App\Support\Notifications;

use App\Enums\ContactRole;

/**
 * Адресат уведомления — типизированный, а не строка.
 *
 * До матрицы адресат был строкой, где «клиент» и «бухгалтер» были волшебными
 * значениями вперемешку с обычными адресами. Читать такой список мог только
 * тот, кто знает про волшебство.
 */
class Destination
{
    public const LOGIN = 'login';

    public const CONTACT_ROLE = 'contact_role';

    public const CONTACT = 'contact';

    public const EMAIL = 'email';

    public const MANAGER = 'manager';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): ?self
    {
        $type = (string) ($row['type'] ?? '');

        if (! in_array($type, self::types(), true)) {
            return null;
        }

        return new self($type, $row);
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::LOGIN, self::CONTACT_ROLE, self::CONTACT, self::EMAIL, self::MANAGER];
    }

    public function role(): ?ContactRole
    {
        return ContactRole::tryFrom((string) ($this->payload['role'] ?? ''));
    }

    public function contactId(): ?int
    {
        $id = $this->payload['contact_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function email(): ?string
    {
        $email = trim((string) ($this->payload['email'] ?? ''));

        return $email === '' ? null : $email;
    }

    /**
     * Подпись адресата по-русски — она же чип в интерфейсе.
     */
    public function label(): string
    {
        return match ($this->type) {
            self::LOGIN => 'На почту партнёра',
            self::MANAGER => 'Персональному менеджеру',
            self::CONTACT_ROLE => $this->role()?->label() ?? 'Контакту',
            self::CONTACT => 'Выбранному человеку',
            self::EMAIL => (string) $this->email(),
            default => $this->type,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->payload, ['type' => $this->type]);
    }
}
