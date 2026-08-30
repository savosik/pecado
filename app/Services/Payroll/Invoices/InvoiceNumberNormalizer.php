<?php

namespace App\Services\Payroll\Invoices;

/**
 * Номер реализации 1С в форме, по которой можно сравнивать.
 *
 * Мост «платёж → накладная» держится на номере документа внутри имени объекта
 * расчётов («Реализация товаров и услуг 29УТ-007699 от 26.08.2026 …»), потому что
 * `settlement_object_uuid` в 1С — не uuid реализации. Номер пишут по-разному:
 * ведущие нули («007699» и «7699»), латинская A в префиксе (`A2УТ-000768`) и
 * кириллическая А в имени объекта. Ключ снимает все три различия.
 */
final class InvoiceNumberNormalizer
{
    /** Кириллические буквы, неотличимые от латинских в номерах 1С. */
    private const LOOKALIKES = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
        'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X',
    ];

    /** Имя объекта расчётов, которое вообще говорит о реализации. */
    private const OBJECT_PREFIX = 'реализация';

    /**
     * «29УТ-007699» → «29YT-7699»; мусор → null.
     */
    public function key(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $normalized = strtr(mb_strtoupper(trim($number)), self::LOOKALIKES);

        if (! preg_match('/^([A-Z0-9]{1,10})-0*(\d+)$/', $normalized, $m)) {
            return null;
        }

        return $m[1].'-'.$m[2];
    }

    /** Имя объекта расчётов аванса: платёж на заказ до отгрузки. */
    private const ORDER_PREFIX = 'заказ клиента';

    /**
     * Ключ реализации из имени объекта расчётов платежа.
     *
     * Только для объектов «Реализация …»: номер заказа с номером накладной
     * сравнивать нельзя — это разные нумерации.
     */
    public function fromObjectName(?string $objectName): ?string
    {
        return $this->keyFromObjectName($objectName, self::OBJECT_PREFIX);
    }

    /**
     * Ключ заказа из имени объекта расчётов аванса («Заказ клиента 29УТ-006085 от …»).
     *
     * Аванс по заказу закрывает реализации этого заказа: деньги пришли раньше
     * отгрузки, задержки нет. Сопоставляется с `orders.erp_number` через тот же ключ.
     */
    public function orderKeyFromObjectName(?string $objectName): ?string
    {
        return $this->keyFromObjectName($objectName, self::ORDER_PREFIX);
    }

    private function keyFromObjectName(?string $objectName, string $prefix): ?string
    {
        if ($objectName === null) {
            return null;
        }

        $name = trim($objectName);

        if (mb_strtolower(mb_substr($name, 0, mb_strlen($prefix))) !== $prefix) {
            return null;
        }

        if (! preg_match('/([A-ZА-Я0-9]{1,10}-\d{3,})/u', mb_strtoupper($name), $m)) {
            return null;
        }

        return $this->key($m[1]);
    }
}
