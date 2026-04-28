<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Model;

/**
 * Резолвер `match_source`/`match_snippet` для документов кабинета.
 * Принимает документ и описание полей по приоритету; возвращает первое
 * найденное совпадение. Если документ присутствует в выдаче, но прямого
 * совпадения не нашли — это `fuzzy` (значит он пришёл из Meilisearch
 * через FuzzyDocumentMatcher).
 *
 * Контракт см. docs/tasks/in-progress/2026-04-28_cabinet-search-match-source.md
 */
class MatchSourceResolver
{
    /**
     * @param  array<int, array{field: string, source: string}>  $directFields
     *                                                                          Прямые поля документа: например, `[['field' => 'number', 'source' => 'number']]`.
     * @param  array<int, array{relation: string, field: string, source: string}>  $relationFields
     *                                                                                              Поля связанных моделей (single relation, например company.name).
     * @param  array<int, array{relation: string, field: string, source: string}>  $itemFields
     *                                                                                          Поля коллекции items (HasMany). У всех записей в массиве должен быть
     *                                                                                          одинаковый `relation`.
     * @return array{source: ?string, snippet: ?string}
     */
    public static function resolve(
        Model $document,
        string $search,
        array $directFields = [],
        array $relationFields = [],
        array $itemFields = [],
    ): array {
        if (trim($search) === '') {
            return ['source' => null, 'snippet' => null];
        }

        foreach ($directFields as $cfg) {
            $value = (string) ($document->{$cfg['field']} ?? '');
            if ($value !== '' && self::contains($value, $search)) {
                return ['source' => $cfg['source'], 'snippet' => self::snippet($value, $search)];
            }
        }

        foreach ($relationFields as $cfg) {
            $rel = $document->{$cfg['relation']};
            if (! $rel) {
                continue;
            }
            $value = (string) ($rel->{$cfg['field']} ?? '');
            if ($value !== '' && self::contains($value, $search)) {
                return ['source' => $cfg['source'], 'snippet' => self::snippet($value, $search)];
            }
        }

        if (! empty($itemFields)) {
            $relation = $itemFields[0]['relation'];
            $items = $document->{$relation} ?? null;
            if ($items !== null) {
                foreach ($items as $item) {
                    foreach ($itemFields as $cfg) {
                        $value = (string) ($item->{$cfg['field']} ?? '');
                        if ($value !== '' && self::contains($value, $search)) {
                            return ['source' => $cfg['source'], 'snippet' => self::snippet($value, $search)];
                        }
                    }
                }
            }
        }

        return ['source' => 'fuzzy', 'snippet' => null];
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return mb_stripos($haystack, $needle) !== false;
    }

    private static function snippet(string $value, string $search, int $maxLength = 120): string
    {
        $length = mb_strlen($value);
        if ($length <= $maxLength) {
            return $value;
        }

        $pos = mb_stripos($value, $search);
        if ($pos === false) {
            return mb_substr($value, 0, $maxLength).'…';
        }

        $start = max(0, $pos - 30);
        $snippet = mb_substr($value, $start, $maxLength);
        if ($start > 0) {
            $snippet = '…'.$snippet;
        }
        if ($start + $maxLength < $length) {
            $snippet .= '…';
        }

        return $snippet;
    }
}
