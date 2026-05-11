<?php

namespace App\Services\Erp\Support;

use App\Enums\ReturnStatus;

/**
 * Маппинг входящих значений статуса заявки на возврат от 1С → канонические ключи.
 *
 * 1С может присылать как уже нормализованные английские ключи из своего
 * справочника (после v15), так и человекочитаемые русские строки. Маппер
 * сворачивает оба варианта в единый `ReturnStatus`.
 *
 * Источник истины — docs-erp/content/rules/returns.md.
 */
final class ReturnStatusMapper
{
    /** Канонические английские ключи (значения ReturnStatus). */
    private const ENGLISH = [
        'pending_approval',
        'for_return',
        'in_reserve',
        'ready_for_shipment',
        'completed',
        'rejected',
    ];

    /** Русские строки 1С → канонические ключи. */
    private const RUSSIAN = [
        // Названия из перечисления 1С (значения).
        'на согласовании' => 'pending_approval',
        'к возврату' => 'for_return',
        'в резерве' => 'in_reserve',
        'к отгрузке' => 'ready_for_shipment',
        'выполнена' => 'completed',
        'отклонена' => 'rejected',
        // Системные имена перечисления 1С (имена элементов справочника).
        'несогласована' => 'pending_approval',
        'не согласована' => 'pending_approval',
        'квозврату' => 'for_return',
        'кобеспечению' => 'in_reserve',
        'котгрузке' => 'ready_for_shipment',
    ];

    /**
     * Legacy-ключи до v15 (когда статусов было пять) → новые.
     *
     * Используются для миграции существующих данных и обратной совместимости
     * приёма событий от 1С в переходный период (см. changelog v15.0.0).
     * `confirmed` сворачивал «КОбеспечению/В резерве» и «КВозврату/К возврату»
     * в одну строку — переводим в `in_reserve` как более ранний (и более
     * частый) этап.
     */
    private const LEGACY = [
        'pending' => 'pending_approval',
        'confirmed' => 'in_reserve',
        'ready_to_ship' => 'ready_for_shipment',
        'closed' => 'completed',
        'cancelled' => 'rejected',
    ];

    /**
     * Привести произвольное входящее значение к канонической строке.
     * Возвращает null, если значение не распознано.
     */
    public static function toCanonical(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($raw));

        if (in_array($normalized, self::ENGLISH, true)) {
            return $normalized;
        }

        if (isset(self::RUSSIAN[$normalized])) {
            return self::RUSSIAN[$normalized];
        }

        if (isset(self::LEGACY[$normalized])) {
            return self::LEGACY[$normalized];
        }

        return null;
    }

    /**
     * Привести значение к ReturnStatus enum.
     * Возвращает null, если значение не распознано.
     */
    public static function toEnum(?string $raw): ?ReturnStatus
    {
        $canonical = self::toCanonical($raw);

        return $canonical ? ReturnStatus::from($canonical) : null;
    }
}
