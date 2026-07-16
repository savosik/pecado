<?php

namespace App\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Построение человекочитаемого имени файла сертификата для скачивания.
 *
 * Проблема: у media имя-хеш (85146c80….pdf) или вовсе без расширения,
 * а на фронте в качестве download-имени подставлялось название сертификата
 * без расширения — файл скачивался «без формата» и не открывался.
 *
 * Здесь формируется имя вида «<название сертификата>.<расширение>»,
 * очищенное от небезопасных для файловой системы символов.
 */
class CertificateFilename
{
    /**
     * Соответствие MIME-типов расширениям — на случай, когда у media
     * нет расширения в file_name (fallback).
     */
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
        'application/vnd.rar' => 'rar',
        'application/x-rar-compressed' => 'rar',
        'application/x-rar' => 'rar',
    ];

    /**
     * Итоговое имя файла для скачивания: «<название>.<расширение>».
     */
    public static function for(string $certificateName, Media $media): string
    {
        $base = self::sanitizeBase($certificateName);
        $extension = self::extension($media);

        if ($base === '') {
            // если название пустое/из одних спецсимволов — берём исходное имя файла
            $base = self::sanitizeBase(pathinfo($media->file_name, PATHINFO_FILENAME)) ?: 'certificate';
        }

        return $extension !== '' ? "{$base}.{$extension}" : $base;
    }

    /**
     * Определить расширение: сначала из file_name, иначе из MIME-типа.
     */
    public static function extension(Media $media): string
    {
        $ext = strtolower((string) pathinfo($media->file_name, PATHINFO_EXTENSION));
        if ($ext !== '') {
            return $ext;
        }

        return self::MIME_EXTENSIONS[strtolower((string) $media->mime_type)] ?? '';
    }

    /**
     * Очистка названия для использования в имени файла:
     * убираем символы, недопустимые в путях, схлопываем пробелы, обрезаем длину.
     * Кириллица сохраняется.
     */
    private static function sanitizeBase(string $name): string
    {
        // недопустимые в именах файлов символы + управляющие
        $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#u', ' ', $name) ?? '';
        // схлопнуть пробелы
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");

        // ограничить длину, чтобы не упереться в лимит файловой системы
        if (mb_strlen($name) > 150) {
            $name = rtrim(mb_substr($name, 0, 150));
        }

        return $name;
    }
}
