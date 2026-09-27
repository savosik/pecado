<?php

namespace App\Support\Content;

/**
 * Полный текст контента (Editor.js JSON или HTML) для агентов и API.
 *
 * В отличие от ContentHelper::extractText, текст не обрезается и сохраняет
 * структуру: заголовки — «## », пункты списков — «- », абзацы — пустой строкой.
 * Блоков в редакторе много и часть из них самописные (баннеры, «до/после»,
 * карточки), поэтому неизвестный блок не пропускается, а разбирается обходом
 * его данных: берутся текстовые поля, ссылки и служебные ключи отбрасываются.
 */
class ContentText
{
    /** Ключи данных блока, в которых не текст для человека. */
    private const SKIP_KEYS = [
        'url', 'href', 'link', 'src', 'file', 'image', 'images', 'video', 'poster', 'icon',
        'id', 'style', 'styles', 'alignment', 'align', 'color', 'background', 'variant', 'type',
        'size', 'width', 'height', 'aspectratio', 'withborder', 'withbackground', 'stretched',
        'meta', 'level', 'class', 'classname', 'theme', 'layout', 'embed', 'service', 'source',
        'productid', 'product_id', 'productids', 'product_ids', 'mime', 'extension',
    ];

    public static function toText(?string $content): string
    {
        $trimmed = trim((string) $content);

        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);

            if (is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
                return self::blocks($decoded['blocks']);
            }
        }

        return self::html($trimmed);
    }

    /**
     * @param  array<int, mixed>  $blocks
     */
    private static function blocks(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            $text = match ($block['type'] ?? '') {
                'header' => self::prefixed('## ', self::inline($data['text'] ?? '')),
                'list', 'checklist' => self::listItems($data['items'] ?? []),
                'quote', 'pullQuote' => self::prefixed('> ', self::inline($data['text'] ?? ''))
                    .(! empty($data['caption']) ? ' — '.self::inline($data['caption']) : ''),
                'table' => self::table($data['content'] ?? []),
                'delimiter', 'raw', 'code', 'embed' => '',
                default => self::collect($data),
            };

            if (trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Текстовые поля произвольного блока в порядке объявления.
     *
     * @param  array<mixed>  $data
     */
    private static function collect(array $data): string
    {
        $lines = [];

        array_walk($data, function ($value, $key) use (&$lines) {
            if (is_string($key) && in_array(strtolower($key), self::SKIP_KEYS, true)) {
                return;
            }

            if (is_array($value)) {
                $nested = self::collect($value);

                if ($nested !== '') {
                    $lines[] = $nested;
                }

                return;
            }

            if (! is_string($value) || preg_match('#^(https?:)?//|^/[\w\-./]+$#u', trim($value))) {
                return;
            }

            $text = self::inline($value);

            if ($text !== '') {
                $lines[] = $text;
            }
        });

        return implode("\n", $lines);
    }

    /**
     * @param  mixed  $items
     */
    private static function listItems($items, int $depth = 0): string
    {
        $lines = [];

        foreach ((array) $items as $item) {
            $text = is_string($item) ? $item : (string) ($item['content'] ?? $item['text'] ?? '');
            $text = self::inline($text);

            if ($text !== '') {
                $lines[] = str_repeat('  ', $depth).'- '.$text;
            }

            if (is_array($item) && ! empty($item['items'])) {
                $lines[] = self::listItems($item['items'], $depth + 1);
            }
        }

        return implode("\n", array_filter($lines, fn ($l) => $l !== ''));
    }

    /**
     * @param  mixed  $rows
     */
    private static function table($rows): string
    {
        $lines = [];

        foreach ((array) $rows as $row) {
            $cells = array_map(fn ($cell) => self::inline(is_string($cell) ? $cell : ''), (array) $row);
            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }

    private static function prefixed(string $prefix, string $text): string
    {
        return $text === '' ? '' : $prefix.$text;
    }

    /** Строка внутри блока: теги и сущности убираются, пробелы схлопываются. */
    private static function inline(mixed $text): string
    {
        if (! is_string($text)) {
            return '';
        }

        $text = preg_replace('#<br\s*/?>#i', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** HTML: блочные теги становятся переносами, заголовки и пункты — разметкой. */
    private static function html(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<h[1-6][^>]*>#i', "\n\n## ", $html) ?? $html;
        $html = preg_replace('#<li[^>]*>#i', "\n- ", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|h[1-6]|ul|ol|table|tr|blockquote|section|article)>#i', "\n\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
