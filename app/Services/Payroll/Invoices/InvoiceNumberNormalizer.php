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

    /**
     * Ключ реализации из имени объекта расчётов платежа.
     *
     * Только для объектов «Реализация …»: платёж на «Заказ клиента» — аванс,
     * задержки у него не бывает, и его номер ни с какой накладной сравнивать нельзя.
     */
    public function fromObjectName(?string $objectName): ?string
    {
        if ($objectName === null) {
            return null;
        }

        $name = trim($objectName);

        if (mb_strtolower(mb_substr($name, 0, mb_strlen(self::OBJECT_PREFIX))) !== self::OBJECT_PREFIX) {
            return null;
        }

        if (! preg_match('/([A-ZА-Я0-9]{1,10}-\d{3,})/u', mb_strtoupper($name), $m)) {
            return null;
        }

        return $this->key($m[1]);
    }
}
