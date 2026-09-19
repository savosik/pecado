<?php

namespace App\Support\Client;

use App\Models\ApiToken;

/**
 * Кем представлен клиент в текущем запросе — человек в кабинете или его агент по токену.
 *
 * Значение проставляется один раз на входе (middleware клиентского API) и
 * читается там, где нужно отметить происхождение: аудит операций, комментарий
 * в истории статусов заказа («отменён клиентом через API»). Как и CrmSource,
 * это контекст, а не параметр сигнатур: одно забытое место — и запись без автора.
 */
final class ClientApiSource
{
    private static ?int $tokenId = null;

    private static ?string $tokenName = null;

    private static ?string $tokenKind = null;

    public static function token(ApiToken $token): void
    {
        self::$tokenId = (int) $token->getKey();
        self::$tokenName = $token->name;
        self::$tokenKind = $token->kind;
    }

    /**
     * Запрос пришёл от чата-помощника (токен вида `assistant`), а не от
     * собственного агента клиента: журнал помечает канал «web-assistant»,
     * а необратимые операции требуют подтверждения кнопкой.
     */
    public static function isAssistant(): bool
    {
        return self::$tokenKind === ApiToken::KIND_ASSISTANT;
    }

    public static function isApi(): bool
    {
        return self::$tokenId !== null;
    }

    public static function tokenId(): ?int
    {
        return self::$tokenId;
    }

    public static function tokenName(): ?string
    {
        return self::$tokenName;
    }

    /**
     * Подпись источника для человекочитаемых записей.
     */
    public static function label(): string
    {
        return self::isApi() ? 'через API («'.self::$tokenName.'»)' : 'из кабинета';
    }

    /**
     * Сброс контекста — тестам и очередям: процесс воркера живёт долго.
     */
    public static function reset(): void
    {
        self::$tokenId = null;
        self::$tokenName = null;
        self::$tokenKind = null;
    }
}
