<?php

namespace App\Support\Crm;

use App\Enums\Crm\BusinessType;
use App\Enums\Crm\ClientLifecycleStatus;

/**
 * Одна строка партнёра из управленческой таблицы продаж.
 *
 * Разбор отделён от записи намеренно: таблицу ведут люди, и её форма меняется —
 * колонки переезжают, подписи месяцев пишутся то «июнь», то «июл». Пока разбор
 * живёт отдельно, эти сюрпризы чинятся в одном месте и проверяются тестом без
 * похода в базу.
 */
final class SalesSheetRow
{
    /** Строка описывает конкретного партнёра, которого нужно найти в базе. */
    public const KIND_CLIENT = 'client';

    /**
     * Строка-«корзина» вида «Новые партнёры Иван»: план на тех, кого ещё не
     * привлекли. Партнёра за ней нет, поэтому она уходит в план менеджера.
     */
    public const KIND_NEW_CLIENTS = 'new_clients';

    /**
     * @param  int  $line  номер строки в файле — чтобы отчёт указывал, где смотреть
     * @param  string  $kind  KIND_CLIENT или KIND_NEW_CLIENTS
     * @param  string|null  $manager  менеджер, как он записан в таблице
     * @param  array<string, float>  $plans  план по месяцам: 'YYYY-MM' => сумма
     */
    public function __construct(
        public readonly int $line,
        public readonly string $name,
        public readonly string $kind = self::KIND_CLIENT,
        public readonly ?string $manager = null,
        public readonly ?ClientLifecycleStatus $status = null,
        public readonly ?BusinessType $businessType = null,
        public readonly ?bool $hasOfflinePoints = null,
        public readonly ?bool $hasOnlineStore = null,
        public readonly ?bool $worksWithMarketplaces = null,
        public readonly ?int $pointsCount = null,
        public readonly array $plans = [],
    ) {}

    /**
     * Поля паспорта, которые строка реально заполняет.
     *
     * Незаданное в таблице остаётся незаданным: пустая ячейка означает «не
     * выясняли», и превращать её в «нет» импорт не вправе.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        $attributes = [
            'business_type' => $this->businessType?->value,
            'has_offline_points' => $this->hasOfflinePoints,
            'has_online_store' => $this->hasOnlineStore,
            'works_with_marketplaces' => $this->worksWithMarketplaces,
            'points_count' => $this->pointsCount,
        ];

        return array_filter($attributes, fn (mixed $value): bool => $value !== null);
    }
}
