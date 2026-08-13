<?php

namespace App\Enums;

/**
 * Вид печатной формы документа, присланной 1С.
 *
 * Справочник ведётся на сайте, а не в 1С: коды нужны фильтру в кабинете и переводу
 * на русский, а 1С в разных конфигурациях называет одни и те же формы по-разному.
 * Неизвестный код не отбрасывается — он превращается в OTHER, а исходные код
 * и название сохраняются в printed_documents.erp_type_code / erp_type_name.
 * По накопившимся кодам сюда добавляются новые виды.
 *
 * Сопоставление кода 1С с этим перечислением — PrintedDocumentTypeMapper.
 * Бизнес-правила — docs-erp/content/rules/printed-documents.md.
 */
enum PrintedDocumentType: string
{
    case CONTRACT = 'contract';
    case AGREEMENT = 'agreement';
    case INVOICE = 'invoice';
    case TAX_INVOICE = 'tax_invoice';
    case CORRECTION_INVOICE = 'correction_invoice';
    case UPD = 'upd';
    case UKD = 'ukd';
    case WAYBILL = 'waybill';
    case CONSIGNMENT_NOTE = 'consignment_note';
    case ACT = 'act';
    case RECONCILIATION_ACT = 'reconciliation_act';
    case SPECIFICATION = 'specification';
    case PRICE_LIST = 'price_list';

    /**
     * Фолбэк для кода, которого нет в этом перечислении.
     *
     * Клиенту показывается erp_type_name — название, как его прислала 1С.
     */
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CONTRACT => 'Договор',
            self::AGREEMENT => 'Соглашение об условиях продаж',
            self::INVOICE => 'Счёт на оплату',
            self::TAX_INVOICE => 'Счёт-фактура',
            self::CORRECTION_INVOICE => 'Корректировочный счёт-фактура',
            self::UPD => 'УПД',
            self::UKD => 'УКД',
            self::WAYBILL => 'Товарная накладная',
            self::CONSIGNMENT_NOTE => 'Транспортная накладная',
            self::ACT => 'Акт выполненных работ',
            self::RECONCILIATION_ACT => 'Акт сверки взаиморасчётов',
            self::SPECIFICATION => 'Спецификация',
            self::PRICE_LIST => 'Прайс-лист',
            self::OTHER => 'Прочее',
        };
    }

    /**
     * Цвет бейджа в списках (colorPalette Chakra UI).
     *
     * Группировка по смыслу: деньги — синие, отгрузка — зелёная, корректировки —
     * оранжевые, правоустанавливающее — фиолетовое.
     */
    public function color(): string
    {
        return match ($this) {
            self::CONTRACT, self::AGREEMENT, self::SPECIFICATION => 'purple',
            self::INVOICE, self::TAX_INVOICE => 'blue',
            self::CORRECTION_INVOICE, self::UKD => 'orange',
            self::UPD, self::WAYBILL, self::CONSIGNMENT_NOTE => 'green',
            self::ACT, self::RECONCILIATION_ACT => 'teal',
            self::PRICE_LIST => 'cyan',
            self::OTHER => 'gray',
        };
    }

    /**
     * Справочник для фильтров и селектов: значение → подпись.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases(),
        );
    }

    /**
     * Допустимые значения фильтра — для валидации запроса.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
