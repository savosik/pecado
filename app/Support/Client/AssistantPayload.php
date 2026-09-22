<?php

namespace App\Support\Client;

/**
 * Как чат-помощник (токен вида assistant) получает ответы MCP: компактно.
 *
 * Отступы pretty-JSON, uuid и коды 1С давали до 40 % объёма строки товара,
 * а страницы по 100 строк — целый каталог за раз. Обычным агентам (Claude Code,
 * Cursor) всё остаётся как было: их контракт не трогаем.
 */
final class AssistantPayload
{
    /** Страница по умолчанию и потолок для чата: клиенту в чате нужно 10–20 строк. */
    public const PER_PAGE = 25;

    /** Служебные поля, которые модели в чате не нужны. */
    public const STRIP_KEYS = ['uuid', 'code', 'barcode', 'erp_id', 'erp_uuid'];

    /**
     * Убрать служебные ключи и null рекурсивно; списки остаются списками.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function slim(array $data): array
    {
        $isList = array_is_list($data);
        $out = [];

        foreach ($data as $key => $value) {
            if (! $isList && (in_array($key, self::STRIP_KEYS, true) || $value === null)) {
                continue;
            }

            $out[$key] = is_array($value) ? self::slim($value) : $value;
        }

        return $isList ? array_values($out) : $out;
    }
}
