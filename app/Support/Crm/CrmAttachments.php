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
    /** Имя коллекции MediaLibrary. */
    public const COLLECTION = 'crm-attachments';

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
