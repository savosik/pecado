<?php

namespace App\Services\Client\Api;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Единый конверт ответа клиентского API.
 *
 * Успех: `{data, meta?}`; ошибка: `{errors: [{code, message, field?}], meta?}`.
 * Один формат на все операции и оба транспорта — агент разбирает ответ одним
 * правилом, а не по-своему на каждый эндпоинт.
 */
final class Envelope
{
    /** Потолок страницы по умолчанию. */
    public const PER_PAGE_MAX = 100;

    /** Потолок для фидов синхронизации (цены, остатки): полный прайс не должен быть 40 страницами. */
    public const PER_PAGE_MAX_FEED = 500;

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function data(array $data, array $meta = []): array
    {
        $envelope = ['data' => $data];

        if ($meta !== []) {
            $envelope['meta'] = $meta;
        }

        return $envelope;
    }

    /**
     * Курсорная страница: `data` — строки через презентер, `meta` — курсоры.
     *
     * @template T
     *
     * @param  CursorPaginator<int, T>  $paginator
     * @param  callable(T): array<string, mixed>  $present
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    public static function cursor(CursorPaginator $paginator, callable $present, array $extraMeta = []): array
    {
        $rows = [];

        foreach ($paginator->items() as $item) {
            $rows[] = $present($item);
        }

        return self::data($rows, $extraMeta + [
            'per_page' => $paginator->perPage(),
            'has_more' => $paginator->hasMorePages(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ]);
    }

    /**
     * Постраничный список (offset) — для поиска по релевантности, где курсор не работает.
     *
     * @template T
     *
     * @param  LengthAwarePaginator<int, T>  $paginator
     * @param  callable(T): array<string, mixed>  $present
     * @return array<string, mixed>
     */
    public static function page(LengthAwarePaginator $paginator, callable $present): array
    {
        $rows = [];

        foreach ($paginator->items() as $item) {
            $rows[] = $present($item);
        }

        return self::data($rows, [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function error(string $code, string $message, ?string $field = null, array $meta = []): array
    {
        $envelope = ['errors' => [array_filter([
            'code' => $code,
            'message' => $message,
            'field' => $field,
        ], fn ($v) => $v !== null)]];

        if ($meta !== []) {
            $envelope['meta'] = $meta;
        }

        return $envelope;
    }

    /**
     * Ошибки валидации Laravel → список ошибок конверта.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    public static function validation(array $errors): array
    {
        $list = [];

        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $list[] = ['code' => 'validation', 'message' => $message, 'field' => $field];
            }
        }

        return ['errors' => $list];
    }

    /**
     * Нормализованный размер страницы: не меньше 1, не больше потолка.
     */
    public static function perPage(mixed $requested, int $default = 50, int $max = self::PER_PAGE_MAX): int
    {
        $value = (int) ($requested ?? $default);

        return max(1, min($max, $value === 0 ? $default : $value));
    }
}
