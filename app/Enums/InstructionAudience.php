<?php

namespace App\Enums;

/**
 * Кому адресована инструкция. Хранится тремя флагами в `instructions`
 * (одна инструкция может быть нужна и складу, и менеджерам), а перечисление
 * даёт имя колонки, подпись и раздел, где её читают.
 */
enum InstructionAudience: string
{
    case CLIENT = 'client';
    case CRM = 'crm';
    case WMS = 'wms';

    public function column(): string
    {
        return match ($this) {
            self::CLIENT => 'for_clients',
            self::CRM => 'for_crm',
            self::WMS => 'for_wms',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CLIENT => 'Клиенты',
            self::CRM => 'Менеджеры (CRM)',
            self::WMS => 'Склад (WMS)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CLIENT => 'green',
            self::CRM => 'blue',
            self::WMS => 'orange',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
