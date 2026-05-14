<?php

namespace App\Http\Controllers\Traits;

/**
 * Общие правила валидации структуры `fields[]` в конструкторе выгрузки.
 *
 * Trait, а не FormRequest, потому что правила нужны и в Admin/, и в User/
 * контроллерах, причём слегка дополняются (Admin требует client_user_id,
 * User — нет), а также используются в preview()-эндпоинтах рядом
 * с минимальным набором правил.
 *
 * При добавлении нового модификатора в ProductExportService.applyModifiers
 * расширяем правила здесь — иначе `$request->validate()` отбросит ключ
 * и backend не получит модификатор, сохранённый из UI.
 */
trait ProductExportFieldValidation
{
    /**
     * Правила валидации для одного элемента в `fields[]`.
     *
     * @return array<string, string>
     */
    protected function exportFieldRules(): array
    {
        return [
            'fields.*.key' => 'required|string',
            'fields.*.label' => 'nullable|string|max:255',
            'fields.*.modifiers' => 'nullable|array',

            // Modifier: price (выбор валюты + арифметика)
            'fields.*.modifiers.currency_id' => 'nullable|integer|exists:currencies,id',

            // Modifier: boolean (метки true/false)
            'fields.*.modifiers.true_value' => 'nullable|string|max:50',
            'fields.*.modifiers.false_value' => 'nullable|string|max:50',

            // Modifier: multi_value (склейка списков)
            'fields.*.modifiers.separator' => 'nullable|string|max:20',
            'fields.*.modifiers.source_separator' => 'nullable|string|max:20',

            // Modifier: numeric / price (арифметика)
            'fields.*.modifiers.multiply' => 'nullable|numeric',
            'fields.*.modifiers.add' => 'nullable|numeric',
            'fields.*.modifiers.integer_if_whole' => 'nullable|boolean',

            // Modifier: substring (пост-обработка строкового значения)
            'fields.*.modifiers.substring_start' => 'nullable|integer|min:0|max:1000',
            'fields.*.modifiers.substring_length' => 'nullable|integer|min:1|max:10000',

            // Modifier: date (форматирование Carbon/DateTime в строку)
            'fields.*.modifiers.format' => 'nullable|string|max:50',
        ];
    }
}
