<?php

namespace App\Support\Search;

/**
 * Подсказки для пустой выдачи (PR 5.3, A-8).
 *
 * Форматирует человеческий совет «что попробовать» когда поиск/фильтры
 * не дали результатов. Контракт см. карточку
 * docs/tasks/.../cabinet-search-suggestions.md
 */
class EmptyResultSuggestion
{
    /**
     * @param  array<string, string>  $activeFilters  Список активных фильтров: метка → значение.
     *                                                Например: ['Статус' => 'Подтверждён', 'Сумма от' => '1000'].
     *                                                Метки используются для построения подсказки —
     *                                                порядок и состав определяет вызывающий код.
     */
    public static function build(string $search, array $activeFilters): ?string
    {
        if (! (bool) config('search-cabinet.suggestions')) {
            return null;
        }

        $search = trim($search);
        $hasSearch = $search !== '';
        $hasFilters = count($activeFilters) > 0;

        if (! $hasSearch && ! $hasFilters) {
            return null;
        }

        $parts = [];

        if ($hasSearch) {
            $parts[] = sprintf(
                'Проверьте написание запроса «%s» или попробуйте более короткое ключевое слово.',
                $search,
            );
        }

        if ($hasFilters) {
            $parts[] = sprintf(
                'Попробуйте сбросить фильтры: %s.',
                implode(', ', array_keys($activeFilters)),
            );
        }

        return implode("\n", $parts);
    }
}
