<?php

namespace App\Services\Erp\Support;

use App\Enums\PrintedDocumentType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сопоставление кода вида печатной формы из 1С с перечислением сайта.
 *
 * Контракт просит латинский код, но рассчитывать на это нельзя: 1С почти наверняка
 * пришлёт имена своего перечисления по-русски, а разные конфигурации называют одни
 * и те же формы по-разному. Поэтому три ступени — точный код, таблица синонимов,
 * фолбэк «Прочее».
 *
 * Фолбэк здесь принципиален. Неизвестный вид не должен ронять документ: 1С печатные
 * формы не хранит, и потерянный PDF перезалить неоткуда. Документ принимается,
 * показывается в разделе «Прочее» под названием из 1С, а исходный код остаётся
 * в базе — по накопившимся кодам заводятся новые виды в PrintedDocumentType.
 */
class PrintedDocumentTypeMapper
{
    /**
     * Синонимы: нормализованное написание → код перечисления.
     *
     * Ключи приводятся к нижнему регистру, из них вычищается всё, кроме букв и цифр,
     * — «Счёт-фактура», «СчетФактура» и «счет фактура» дают одну и ту же строку.
     * Буква «ё» отдельно сводится к «е»: 1С пишет и так, и так.
     *
     * @var array<string, PrintedDocumentType>
     */
    private const ALIASES = [
        'договор' => PrintedDocumentType::CONTRACT,
        'договорсклиентом' => PrintedDocumentType::CONTRACT,
        'соглашение' => PrintedDocumentType::AGREEMENT,
        'соглашениесклиентом' => PrintedDocumentType::AGREEMENT,
        'соглашениеобусловияхпродаж' => PrintedDocumentType::AGREEMENT,
        'счет' => PrintedDocumentType::INVOICE,
        'счетнаоплату' => PrintedDocumentType::INVOICE,
        'счетнаоплатуклиенту' => PrintedDocumentType::INVOICE,
        'счетфактура' => PrintedDocumentType::TAX_INVOICE,
        'счетфактуравыданный' => PrintedDocumentType::TAX_INVOICE,
        'корректировочныйсчетфактура' => PrintedDocumentType::CORRECTION_INVOICE,
        'корректировочныйсчетфактуравыданный' => PrintedDocumentType::CORRECTION_INVOICE,
        'упд' => PrintedDocumentType::UPD,
        'универсальныйпередаточныйдокумент' => PrintedDocumentType::UPD,
        'укд' => PrintedDocumentType::UKD,
        'универсальныйкорректировочныйдокумент' => PrintedDocumentType::UKD,
        'торг12' => PrintedDocumentType::WAYBILL,
        'товарнаянакладная' => PrintedDocumentType::WAYBILL,
        'накладная' => PrintedDocumentType::WAYBILL,
        'транспортнаянакладная' => PrintedDocumentType::CONSIGNMENT_NOTE,
        'ттн' => PrintedDocumentType::CONSIGNMENT_NOTE,
        'товарнотранспортнаянакладная' => PrintedDocumentType::CONSIGNMENT_NOTE,
        'акт' => PrintedDocumentType::ACT,
        'актвыполненныхработ' => PrintedDocumentType::ACT,
        'актобоказанииуслуг' => PrintedDocumentType::ACT,
        'актсверки' => PrintedDocumentType::RECONCILIATION_ACT,
        'актсверкивзаиморасчетов' => PrintedDocumentType::RECONCILIATION_ACT,
        'спецификация' => PrintedDocumentType::SPECIFICATION,
        'прайслист' => PrintedDocumentType::PRICE_LIST,
        'прайс' => PrintedDocumentType::PRICE_LIST,
    ];

    /**
     * @param  string|null  $code  Код вида как прислала 1С (`type_code`)
     * @param  string|null  $name  Название вида как прислала 1С (`type_name`)
     */
    public function map(?string $code, ?string $name = null): PrintedDocumentType
    {
        foreach ([$code, $name] as $candidate) {
            $type = $this->tryResolve($candidate);

            if ($type !== null) {
                return $type;
            }
        }

        $this->logUnknown($code, $name);

        return PrintedDocumentType::OTHER;
    }

    private function tryResolve(?string $value): ?PrintedDocumentType
    {
        $raw = is_string($value) ? trim($value) : '';

        if ($raw === '') {
            return null;
        }

        // Точное совпадение с кодом перечисления — быстрый путь для 1С,
        // выполнившей контракт буквально.
        $type = PrintedDocumentType::tryFrom(mb_strtolower($raw));

        // OTHER присылать явно незачем, но если прислали — это «неизвестный вид»,
        // а не повод считать код разобранным: дальше ещё проверяется type_name.
        if ($type !== null && $type !== PrintedDocumentType::OTHER) {
            return $type;
        }

        return self::ALIASES[$this->normalize($raw)] ?? null;
    }

    /**
     * «Счёт-фактура выданный» → «счетфактуравыданныи».
     */
    private function normalize(string $value): string
    {
        $value = str_replace(['ё', 'Ё'], 'е', mb_strtolower($value));

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }

    /**
     * Лог с троттлингом на час: первичная выгрузка может принести десятки тысяч
     * форм одного незнакомого вида, и без ограничения лог заливает диск.
     */
    private function logUnknown(?string $code, ?string $name): void
    {
        $key = 'pdoc_unknown_type:'.md5((string) $code.'|'.(string) $name);

        if (! Cache::add($key, 1, 3600)) {
            return;
        }

        Log::info('printed_document: неизвестный вид печатной формы, показан как «Прочее»', [
            'type_code' => $code,
            'type_name' => $name,
        ]);
    }
}
