<?php

namespace App\Support\Logistics;

/**
 * Строка задания логисту: одна отгрузка одному контрагенту.
 *
 * Таблицу ведёт отдел продаж вручную, поэтому здесь только то, что нужно для
 * восстановления адреса клиента: кому везём, куда и чем. Остальные колонки
 * (документы, поручения водителю, объём) к справочнику адресов отношения не имеют.
 */
final class ShipmentRow
{
    public function __construct(
        /** Год отгрузки — берётся из названия листа: даты внутри листа заполнены неровно. */
        public readonly int $year,
        public readonly string $sheet,
        public readonly int $line,
        /** Контрагент так, как его написал менеджер: «Авента ООО г.Новосибирск». */
        public readonly string $client,
        /** Блок «Получатель (ИНН, ФИО, телефон)» — из него достаётся ИНН. */
        public readonly string $recipient,
        public readonly string $address,
        /** Тип доставки: «доставка», «ТК Деловые линии», «самовывоз». */
        public readonly string $delivery,
    ) {}

    public function hasAddress(): bool
    {
        return trim($this->address) !== '';
    }
}
