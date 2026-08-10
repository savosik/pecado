<?php

namespace App\Support\Crm;

/**
 * Кто создаёт запись в CRM — человек в интерфейсе или ИИ-агент.
 *
 * Значение проставляется один раз на входе (middleware агентского гейта) и
 * читается моделями при создании. Альтернатива — тащить параметр `source`
 * через сигнатуры CrmTaskService, CrmEmailService и остальных — означала бы,
 * что достаточно одного забытого места, и в ленте партнёра появится запись
 * неизвестного происхождения. Разбор «кто это написал партнёру» после такого
 * упирается в тупик, поэтому источник проставляется не вызовом, а контекстом.
 */
final class CrmSource
{
    public const WEB = 'web';

    public const AGENT = 'agent';

    private static string $current = self::WEB;

    private static ?string $label = null;

    public static function current(): string
    {
        return self::$current;
    }

    /**
     * @param  string|null  $label  имя токена — попадает в аудит каждой операции
     */
    public static function agent(?string $label = null): void
    {
        self::$current = self::AGENT;
        self::$label = $label;
    }

    /**
     * Имя токена, которым представился агент. Для человека — null.
     */
    public static function label(): ?string
    {
        return self::$label;
    }

    /**
     * Вернуть контекст к человеку. Нужен тестам и очередям: процесс воркера
     * живёт долго, и «агент» протёк бы в следующие задания.
     */
    public static function reset(): void
    {
        self::$current = self::WEB;
        self::$label = null;
    }

    public static function isAgent(): bool
    {
        return self::$current === self::AGENT;
    }
}
