<?php

namespace App\Models\Concerns;

use App\Support\Crm\CrmAttachments;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;

/**
 * Вложения CRM: единая коллекция на клиенте, заказе, реализации и комментарии.
 *
 * Своей таблицы вложений намеренно нет — `media` уже полиморфна, настроена на MEDIA_DISK
 * (S3/MinIO), имеет санитайзер имён и сервис-обёртку. Отдельная таблица дала бы второй
 * механизм хранения со своим диском, своим удалением файлов и своими URL: ровно так
 * сделано в `kanban_task_attachments`, и это исключение, а не образец.
 *
 * Параметры коллекции живут в `CrmAttachments` — константы трейта в PHP нельзя читать
 * через имя трейта, а ссылаться на них нужно и там, где модели нет (валидация, тесты).
 *
 * Модель, подключающая трейт, обязана объявить `implements HasMedia` и не должна
 * подключать `InteractsWithMedia` ещё и напрямую — `registerMediaCollections()`
 * из двух трейтов даёт коллизию методов.
 */
trait HasCrmAttachments
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(CrmAttachments::COLLECTION)
            ->acceptsMimeTypes(CrmAttachments::MIMES);

        // Голос живёт в своей коллекции: у него другой жизненный цикл
        // и другой способ просмотра, чем у счетов и спецификаций.
        $this->addMediaCollection(CrmAttachments::VOICE_COLLECTION)
            ->acceptsMimeTypes(CrmAttachments::VOICE_MIMES);
    }

    /**
     * @return MediaCollection<int, \Spatie\MediaLibrary\MediaCollections\Models\Media>
     */
    public function crmAttachments(): MediaCollection
    {
        return $this->getMedia(CrmAttachments::COLLECTION);
    }

    /**
     * Голосовые записи — досье, надиктованное менеджером.
     *
     * @return MediaCollection<int, \Spatie\MediaLibrary\MediaCollections\Models\Media>
     */
    public function crmVoiceNotes(): MediaCollection
    {
        return $this->getMedia(CrmAttachments::VOICE_COLLECTION);
    }
}
