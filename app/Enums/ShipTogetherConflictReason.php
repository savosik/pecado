<?php

namespace App\Enums;

/**
 * Коды отказа группы совместной отгрузки из order.updated 1С (v16.11.0).
 *
 * Перечисление согласовано в топике №7 Agent Hub; `NO_RESPONSE` — единственный
 * локальный код сайта: итог из 1С не пришёл за страховочный срок.
 */
enum ShipTogetherConflictReason: string
{
    case TIMEOUT = 'timeout';
    case MANIFEST_MISMATCH = 'manifest_mismatch';
    case NOT_RESERVED = 'not_reserved';
    case INCOMPATIBLE = 'incompatible';
    case MERGE_FORBIDDEN = 'merge_forbidden';
    case SHORTAGE = 'shortage';
    case PROCESSING_ERROR = 'processing_error';
    case NO_RESPONSE = 'no_response';

    /** Текст для клиента — код 1С сам по себе ему ничего не говорит. */
    public function label(): string
    {
        return match ($this) {
            self::TIMEOUT => 'Склад не получил все заказы группы вовремя',
            self::MANIFEST_MISMATCH => 'Состав группы не совпал между сообщениями',
            self::NOT_RESERVED => 'Один из заказов уже не в резерве',
            self::INCOMPATIBLE => 'Заказы нельзя отгрузить одним документом: отличаются реквизиты',
            self::MERGE_FORBIDDEN => 'Объединение отгрузок для вашего договора запрещено',
            self::SHORTAGE => 'По одному из заказов не хватило товара',
            self::PROCESSING_ERROR => 'Техническая ошибка на складе, заказы не тронуты',
            self::NO_RESPONSE => 'Склад не ответил за отведённое время',
        };
    }

    public static function labelFor(?string $code): string
    {
        return self::tryFrom((string) $code)?->label() ?? 'Причина не указана';
    }
}
