<?php

namespace App\Enums;

enum ExportFormat: string
{
    case JSON = 'json';
    case CSV = 'csv';
    case XML = 'xml';
    case XLS = 'xls';

    public function label(): string
    {
        return match ($this) {
            self::JSON => 'JSON',
            self::CSV => 'CSV',
            self::XML => 'XML',
            self::XLS => 'XLS (Excel)',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::JSON => 'json',
            self::CSV => 'csv',
            self::XML => 'xml',
            self::XLS => 'xlsx',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::JSON => 'application/json',
            self::CSV => 'text/csv',
            self::XML => 'application/xml',
            self::XLS => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
