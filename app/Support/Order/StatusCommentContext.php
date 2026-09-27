<?php

namespace App\Support\Order;

/**
 * Комментарий к смене статуса заказа для OrderStatusHistory.
 *
 * Раньше сервисы клали его в `request()->merge(['status_comment' => …])`, и
 * модель читала его из HTTP-запроса. Сервис, зависящий от запроса, работает
 * только в одном транспорте: из MCP, очереди или консоли запроса нет либо он
 * чужой. Контекст задаётся на время операции и снимается после неё.
 */
final class StatusCommentContext
{
    private static ?string $comment = null;

    public static function set(?string $comment): void
    {
        self::$comment = $comment;
    }

    /**
     * Комментарий текущей операции; в вебе — из поля формы `status_comment`.
     */
    public static function current(): ?string
    {
        if (self::$comment !== null) {
            return self::$comment;
        }

        $fromRequest = app()->bound('request') ? request()->input('status_comment') : null;

        return is_string($fromRequest) && $fromRequest !== '' ? $fromRequest : null;
    }

    public static function reset(): void
    {
        self::$comment = null;
    }

    /**
     * Выполнить действие с заданным комментарием и снять его после.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public static function with(string $comment, callable $action): mixed
    {
        $previous = self::$comment;
        self::$comment = $comment;

        try {
            return $action();
        } finally {
            self::$comment = $previous;
        }
    }
}
