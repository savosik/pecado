<?php

namespace App\Subscriptions;

/**
 * DTO с содержимым уведомления об изменении сущности.
 *
 * Каналонезависимое описание «что произошло»: раздел, владелец (кому
 * принадлежит сущность), заголовок, тело (готовый русский текст, напр.
 * summary из OrderChangeLog), ссылка на сущность в кабинете. Один и тот
 * же notice рассылается всем подписчикам раздела по всем каналам.
 */
class EntityChangeNotice
{
    /**
     * @param  string  $section  ключ раздела ('orders', …)
     * @param  int  $ownerUserId  владелец сущности (по его подпискам ищем адресатов)
     * @param  string  $title  заголовок уведомления (тема письма)
     * @param  string  $body  тело — готовый человекочитаемый текст изменения
     * @param  string|null  $url  абсолютная ссылка на сущность в кабинете
     * @param  string|null  $entityLabel  краткая метка сущности (напр. номер заказа)
     */
    public function __construct(
        public string $section,
        public int $ownerUserId,
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $entityLabel = null,
    ) {}
}
