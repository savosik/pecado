<?php

namespace App\Support\MediaLibrary;

use Illuminate\Support\Str;
use Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer;

/**
 * Санитайзер имён файлов медиа-библиотеки.
 *
 * Why: штатный DefaultFileNamer/дефолтный санитайзер Spatie убирает только
 * управляющие символы и `# / \ пробел`, но оставляет кириллицу, запятые, скобки
 * и точки. Такие имена ломают партнёрские выгрузки: URL с запятой внутри имени
 * файла (`ChatGPT-Image-2-июл.-2026-г.,-12_36_43.png`) рвётся на части, когда
 * клиент разбивает поле «Дополнительные изображения» по запятой, и картинки
 * становятся «битыми». Кириллица в URL так же плохо переносится импортёрами.
 *
 * Этот namer транслитерирует имя в ASCII и оставляет только безопасный набор
 * символов `[A-Za-z0-9_-]`, сохраняя оригинальное расширение (его добавляет
 * FileAdder отдельно).
 */
class SanitizingFileNamer extends DefaultFileNamer
{
    public function originalFileName(string $fileName): string
    {
        $baseName = parent::originalFileName($fileName);

        return static::sanitizeBaseName($baseName);
    }

    /**
     * Привести базовое имя файла (без расширения) к безопасному ASCII-виду.
     *
     * Пустой результат заменяется на `file`, чтобы имя никогда не было пустым.
     */
    public static function sanitizeBaseName(string $baseName): string
    {
        // Транслитерация кириллицы и прочих не-ASCII символов.
        $ascii = Str::ascii($baseName);

        // Разрешаем только латиницу, цифры, дефис и подчёркивание.
        // Всё остальное (пробелы, запятые, точки, скобки) → дефис.
        $ascii = preg_replace('/[^A-Za-z0-9_-]+/', '-', $ascii);

        // Схлопываем повторяющиеся дефисы и убираем их по краям.
        $ascii = preg_replace('/-+/', '-', $ascii);
        $ascii = trim($ascii, '-_');

        return $ascii === '' ? 'file' : $ascii;
    }
}
