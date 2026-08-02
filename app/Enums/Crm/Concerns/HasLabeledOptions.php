<?php

namespace App\Enums\Crm\Concerns;

/**
 * Список вариантов енума для селектов фронтенда.
 *
 * Русские подписи живут в PHP-енуме и оттуда же уезжают в интерфейс: дублировать их
 * в JSX означало бы, что новый вариант появляется в базе, но не появляется в списке.
 */
trait HasLabeledOptions
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
