<?php

namespace App\Enums;

/**
 * Формат файла печатной формы (v16.6.0).
 *
 * Формат определяется по **сигнатуре содержимого**, а не по `mime_type` из сообщения
 * и не по расширению имени файла: и то и другое задаёт 1С, а клиенту важно, что файл
 * реально откроется. Расхождение mime с содержимым не теряет документ — сайт верит
 * содержимому и пишет предупреждение в лог.
 *
 * Бизнес-правила — docs-erp/content/rules/printed-documents.md.
 */
enum PrintedDocumentFormat: string
{
    case PDF = 'pdf';
    case XLSX = 'xlsx';
    case XLS = 'xls';

    /** Сколько байтов с начала файла нужно прочитать, чтобы различить все форматы. */
    public const SIGNATURE_LENGTH = 8;

    public function mime(): string
    {
        return match ($this) {
            self::PDF => 'application/pdf',
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::XLS => 'application/vnd.ms-excel',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    /** Подпись формата для списков в кабинете и CRM. */
    public function label(): string
    {
        return match ($this) {
            self::PDF => 'PDF',
            self::XLSX => 'Excel (XLSX)',
            self::XLS => 'Excel (XLS)',
        };
    }

    /**
     * Формат по первым байтам файла. null — формат неизвестен, документ отклоняется.
     *
     * XLSX — обычный ZIP-контейнер (`PK\x03\x04`), поэтому по сигнатуре он неотличим
     * от любого другого ZIP. Это осознанный размен: строгую проверку структуры OOXML
     * пришлось бы делать распаковкой, а 1С кладёт в обменный бакет печатные формы,
     * а не архивы. XLS — документ OLE2 (`D0 CF 11 E0 A1 B1 1A E1`).
     */
    public static function detect(string $head): ?self
    {
        if (str_starts_with($head, '%PDF-')) {
            return self::PDF;
        }

        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
            return self::XLSX;
        }

        if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return self::XLS;
        }

        return null;
    }

    /** Формат по mime-типу из сообщения 1С — запасной путь, когда файла уже нет под рукой. */
    public static function fromMime(?string $mime): ?self
    {
        return match (strtolower(trim((string) $mime))) {
            'application/pdf' => self::PDF,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => self::XLSX,
            'application/vnd.ms-excel', 'application/excel', 'application/x-excel' => self::XLS,
            default => null,
        };
    }
}
