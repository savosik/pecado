<?php

namespace App\Services\Assistant;

/**
 * Контекст страницы, с которой клиент пишет: где он сейчас на сайте.
 *
 * Приходит с фронта (тип, идентификатор, заголовок, адрес) и добавляется к
 * ходу клиента текстовым блоком — в системный промпт не вписывается, чтобы
 * не ломать кеш и не давать странице править инструкции. Только известные
 * поля, только обрезанные строки: это данные из браузера.
 */
final class PageContext
{
    public const TYPES = ['home', 'catalog', 'category', 'product', 'defects', 'search', 'cart', 'checkout', 'cabinet', 'order', 'orders', 'reserves', 'shipments', 'documents', 'finance', 'returns', 'promotions', 'faq', 'other'];

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{type: string, id: string|null, title: string|null, url: string|null}|null
     */
    public static function sanitize(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $type = (string) ($raw['type'] ?? 'other');

        if (! in_array($type, self::TYPES, true)) {
            $type = 'other';
        }

        $clean = static fn (mixed $value, int $max): ?string => is_scalar($value) && trim((string) $value) !== ''
            ? mb_substr(trim((string) $value), 0, $max)
            : null;

        return [
            'type' => $type,
            'id' => $clean($raw['id'] ?? null, 64),
            'title' => $clean($raw['title'] ?? null, 160),
            'url' => $clean($raw['url'] ?? null, 300),
        ];
    }

    /**
     * Строка для хода клиента: «Страница: товар «…» (арт. …), /products/…».
     *
     * @param  array{type: string, id: string|null, title: string|null, url: string|null}|null  $page
     */
    public static function describe(?array $page): ?string
    {
        if ($page === null) {
            return null;
        }

        $label = match ($page['type']) {
            'home' => 'главная',
            'catalog' => 'каталог',
            'category' => 'категория каталога',
            'product' => 'карточка товара',
            'defects' => 'раздел «Уценка»',
            'search' => 'поиск по каталогу',
            'cart' => 'корзина',
            'checkout' => 'оформление заказа',
            'cabinet' => 'кабинет',
            'order' => 'карточка заказа',
            'orders' => 'список заказов',
            'reserves' => 'резервы',
            'shipments' => 'реализации',
            'documents' => 'документы',
            'finance' => 'оплаты и долг',
            'returns' => 'возвраты',
            'promotions' => 'акции',
            'faq' => 'вопросы и ответы',
            default => 'страница сайта',
        };

        $parts = [$label];

        if ($page['title']) {
            $parts[] = '«'.$page['title'].'»';
        }

        if ($page['id']) {
            $parts[] = '(id '.$page['id'].')';
        }

        if ($page['url']) {
            $parts[] = $page['url'];
        }

        return 'Страница: '.implode(' ', $parts);
    }
}
