<?php

namespace App\Support\Crm;

/**
 * Параметры вложений CRM: имя коллекции, допустимые типы, лимит размера.
 *
 * Вынесены из трейта `HasCrmAttachments` в отдельный класс, потому что константы трейта
 * в PHP нельзя читать через имя трейта — только через использующий класс. А ссылаться
 * на них нужно там, где модели нет вовсе: в правилах валидации формы загрузки,
 * в контроллере и в тестах.
 */
final class CrmAttachments
{
    /** Имя коллекции MediaLibrary для документов и изображений. */
    public const COLLECTION = 'crm-attachments';

    /**
     * Коллекция голосовых записей (crm-25).
     *
     * Отдельная от документов намеренно: у голосового досье другой жизненный
     * цикл и другой способ просмотра. Смешавшись со спецификациями и счетами
     * в одном списке вложений, надиктованная заметка потерялась бы там,
     * где её как раз и ищут.
     */
    public const VOICE_COLLECTION = 'crm-voice';

    /**
     * Форматы голосовых записей.
     *
     * Chromium пишет в webm/opus, Safari — в mp4/aac: поддерживаем оба,
     * иначе на маке запись просто не отправится. Остальные — на случай
     * загрузки готового файла с диктофона.
     *
     * @var list<string>
     */
    public const VOICE_MIMES = [
        'audio/webm',
        'audio/ogg',
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
        'audio/x-wav',
        'audio/aac',
    ];

    /**
     * Предельная длительность записи, секунды.
     *
     * Ограничение в интерфейсе, а не только по размеру файла: отказ должен
     * приходить до загрузки, а не после десятиминутной записи, отправленной
     * по мобильному интернету.
     */
    public const VOICE_MAX_SECONDS = 300;

    /**
     * Разрешённые типы файлов — документы и изображения, по образцу `UserQuestion`:
     * менеджеры прикладывают спецификации, счета и фото товара, а не произвольные бинарники.
     *
     * @var list<string>
     */
    public const MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain',
    ];

    /**
     * Лимит на файл, МБ. Тот же порог, что в Kanban API — чтобы у пользователей
     * во всём проекте был один понятный предел.
     */
    public const MAX_MB = 20;
}
