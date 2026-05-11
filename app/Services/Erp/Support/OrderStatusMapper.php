<?php

namespace App\Services\Erp\Support;

use App\Enums\OrderStatus;

/**
 * Маппинг входящих значений статуса заказа от 1С → канонические ключи.
 *
 * 1С может присылать как уже нормализованные английские ключи (из своего
 * справочника после v14), так и человекочитаемые русские строки из старой
 * версии перечисления. Маппер сворачивает оба варианта в единый
 * `OrderStatus`.
 *
 * Источник истины — docs-erp/content/rules/orders.md (#маппинг-статусов).
 */
final class OrderStatusMapper
{
    /** Канонические английские ключи (значения OrderStatus). */
    private const ENGLISH = [
        'pending_approval',
        'pending_payment_before_provision',
        'ready_for_provision',
        'pending_payment_before_shipment',
        'awaiting_provision',
        'ready_for_shipment',
        'shipping',
        'awaiting_payment',
        'ready_for_closure',
        'closed',
    ];

    /** Русские строки 1С → канонические ключи. */
    private const RUSSIAN = [
        'ожидается согласование' => 'pending_approval',
        'ожидается оплата до обеспечения' => 'pending_payment_before_provision',
        'готов к обеспечению' => 'ready_for_provision',
        'ожидается оплата до отгрузки' => 'pending_payment_before_shipment',
        'ожидается обеспечение' => 'awaiting_provision',
        'готов к отгрузке' => 'ready_for_shipment',
        'в процессе отгрузки' => 'shipping',
        'ожидается оплата' => 'awaiting_payment',
        'готов к закрытию' => 'ready_for_closure',
        'закрыт' => 'closed',
    ];

    /**
     * Legacy-ключи до v14 (когда статусов было всего 5) → новые.
     *
     * Используются только для миграции существующих данных и обратной
     * совместимости приёма событий от 1С в течение переходного периода.
     */
    private const LEGACY = [
        'pending' => 'pending_approval',
        'confirmed' => 'ready_for_provision',
        'ready_to_ship' => 'ready_for_shipment',
        'deleted' => 'closed',
        // Старые русские строки до v14 (см. предыдущую версию orders.md).
        'не согласован' => 'pending_approval',
        'к выполнению' => 'ready_for_provision',
        'к отгрузке' => 'ready_for_shipment',
        'к_отгрузке' => 'ready_for_shipment',
        'удален' => 'closed',
        'удалён' => 'closed',
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
     * Привести значение к OrderStatus enum.
     * Возвращает null, если значение не распознано.
     */
    public static function toEnum(?string $raw): ?OrderStatus
    {
        $canonical = self::toCanonical($raw);

        return $canonical ? OrderStatus::from($canonical) : null;
    }

    /**
     * Распознан ли как «удалённый в 1С» (для триггера soft-delete на сайте).
     */
    public static function isDeletedMarker(?string $raw): bool
    {
        if ($raw === null) {
            return false;
        }

        $normalized = mb_strtolower(trim($raw));

        return in_array($normalized, ['deleted', 'удален', 'удалён'], true);
    }
}
