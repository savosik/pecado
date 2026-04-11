<?php

namespace App\Helpers;

/**
 * Извлекает текстовое содержимое из JSON-блоков Editor.js или HTML.
 * Используется для SEO-описаний и превью, где нужен чистый текст.
 */
class ContentHelper
{
    /**
     * Извлечь plain-text из контента (JSON или HTML).
     */
    public static function extractText(?string $content, int $limit = 160): string
    {
        if (empty($content)) {
            return '';
        }

        $trimmed = trim($content);

        // Попробуем JSON
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['blocks'])) {
                return self::blocksToText($decoded['blocks'], $limit);
            }
        }

        // HTML fallback
        return \Illuminate\Support\Str::limit(strip_tags($content), $limit);
    }

    /**
     * Извлечь текст из массива блоков.
     */
    private static function blocksToText(array $blocks, int $limit): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $data = $block['data'] ?? [];
            $type = $block['type'] ?? '';

            switch ($type) {
                case 'paragraph':
                case 'quote':
                case 'pullQuote':
                    if (!empty($data['text'])) {
                        $parts[] = strip_tags($data['text']);
                    }
                    break;
                case 'header':
                    if (!empty($data['text'])) {
                        $parts[] = strip_tags($data['text']);
                    }
                    break;
                case 'list':
                    foreach ($data['items'] ?? [] as $item) {
                        $text = is_string($item) ? $item : ($item['content'] ?? '');
                        $parts[] = strip_tags($text);
                    }
                    break;
                case 'warning':
                case 'infoBox':
                    if (!empty($data['title'])) $parts[] = strip_tags($data['title']);
                    if (!empty($data['message'])) $parts[] = strip_tags($data['message']);
                    break;
            }

            // Достаточно текста для лимита
            $joined = implode(' ', $parts);
            if (mb_strlen($joined) > $limit) {
                return \Illuminate\Support\Str::limit($joined, $limit);
            }
        }

        return \Illuminate\Support\Str::limit(implode(' ', $parts), $limit);
    }
}
