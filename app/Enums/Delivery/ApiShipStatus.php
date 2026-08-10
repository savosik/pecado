<?php

namespace App\Enums\Delivery;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Унифицированные статусы отправления в ApiShip.
 *
 * Агрегатор приводит статусы всех перевозчиков к этому списку — собственный код
 * перевозчика приезжает рядом отдельным полем (`providerCode`). Источник:
 * https://docs.apiship.ru/docs/delivery-services/status-mapping/
 *
 * Ключи не наши и меняться не должны: по ним приходят вебхуки ORDER_STATUS.
 * Незнакомый ключ не роняет обработку — StatusSynchronizer сохранит его как есть
 * и подставит UNKNOWN, иначе новый статус у перевозчика ломал бы приём вебхуков.
 */
enum ApiShipStatus: string
{
    use HasLabeledOptions;

    case UPLOADING = 'uploading';
    case UPLOADING_ERROR = 'uploadingError';
    case UPLOADED = 'uploaded';
    case ON_POINT_IN = 'onPointIn';
    case ON_WAY = 'onWay';
    case ON_POINT_OUT = 'onPointOut';
    case DELIVERING = 'delivering';
    case READY_FOR_RECIPIENT = 'readyForRecipient';
    case DELIVERED = 'delivered';
    case RETURNING = 'returning';
    case RETURNED = 'returned';
    case RETURNED_FROM_DELIVERY = 'returnedFromDelivery';
    case RETURN_READY = 'returnReady';
    case PARTIAL_RETURN = 'partialReturn';
    case DELIVERY_CANCELED = 'deliveryCanceled';
    case LOST = 'lost';
    case PROBLEM = 'problem';
    case UNKNOWN = 'unknown';
    case NOT_APPLICABLE = 'notApplicable';

    public function label(): string
    {
        return match ($this) {
            self::UPLOADING => 'Передаётся перевозчику',
            self::UPLOADING_ERROR => 'Ошибка передачи перевозчику',
            self::UPLOADED => 'Принят перевозчиком',
            self::ON_POINT_IN => 'Принят на складе отправления',
            self::ON_WAY => 'В пути',
            self::ON_POINT_OUT => 'Прибыл на склад назначения',
            self::DELIVERING => 'Передан на доставку',
            self::READY_FOR_RECIPIENT => 'Готов к выдаче',
            self::DELIVERED => 'Доставлен получателю',
            self::RETURNING => 'Возвращается отправителю',
            self::RETURNED => 'Возвращён отправителю',
            self::RETURNED_FROM_DELIVERY => 'Возвращён с доставки',
            self::RETURN_READY => 'Возврат подготовлен',
            self::PARTIAL_RETURN => 'Частичный возврат',
            self::DELIVERY_CANCELED => 'Доставка отменена',
            self::LOST => 'Утерян',
            self::PROBLEM => 'Проблема с доставкой',
            self::UNKNOWN => 'Статус неизвестен',
            self::NOT_APPLICABLE => 'Статус не применим',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::DELIVERED => 'green',
            self::UPLOADING, self::UNKNOWN, self::NOT_APPLICABLE => 'gray',
            self::UPLOADED, self::ON_POINT_IN => 'blue',
            self::ON_WAY, self::ON_POINT_OUT, self::DELIVERING => 'teal',
            self::READY_FOR_RECIPIENT => 'cyan',
            self::RETURNING, self::RETURNED, self::RETURNED_FROM_DELIVERY,
            self::RETURN_READY, self::PARTIAL_RETURN => 'orange',
            self::UPLOADING_ERROR, self::DELIVERY_CANCELED, self::LOST, self::PROBLEM => 'red',
        };
    }

    /**
     * Финальный статус: дальше перевозчик ничего не пришлёт.
     *
     * По нему сверка статусов перестаёт дёргать заявку — иначе опрос вечно таскал бы
     * доставленные полгода назад отправки.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::DELIVERED, self::RETURNED, self::LOST,
            self::DELIVERY_CANCELED, self::UPLOADING_ERROR => true,
            default => false,
        };
    }

    /**
     * Внутренний статус отправки, соответствующий состоянию у перевозчика.
     *
     * Маппинг сознательно грубее исходного списка: складу в журнале нужны пять
     * состояний, а все девятнадцать оттенков видны в карточке и истории.
     */
    public function toShipmentStatus(): DeliveryShipmentStatus
    {
        return match ($this) {
            self::UPLOADING, self::UPLOADED => DeliveryShipmentStatus::SUBMITTED,
            self::UPLOADING_ERROR => DeliveryShipmentStatus::FAILED,
            self::DELIVERED => DeliveryShipmentStatus::DELIVERED,
            self::DELIVERY_CANCELED => DeliveryShipmentStatus::CANCELLED,
            // Утеря и возврат — это не «доставлено» и не «отменено»: груз ещё
            // в работе у перевозчика, и отправка должна оставаться на радаре склада.
            default => DeliveryShipmentStatus::IN_TRANSIT,
        };
    }
}
