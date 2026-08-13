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
     * @param  array<int, array<string, mixed>>  $rows  структурированные блоки изменения
     *                                                  для красивой вёрстки письма. Каждый элемент — один из:
     *                                                  ['type'=>'diff', 'label'=>, 'old'=>, 'new'=>] — параметр «было → стало»;
     *                                                  ['type'=>'action', 'kind'=>'added|removed|modified|shortfall|partial', 'label'=>, 'text'=>];
     *                                                  ['type'=>'note', 'text'=>] — вводная строка/подзаголовок.
     *                                                  Если пусто — шаблон откатывается на построчный вывод $body.
     * @param  string|null  $event  тип события раздела (ключ из config/subscriptions.php →
     *                              sections.{section}.events, для заказов — order_change_logs.type).
     *                              По нему фильтруются подписки: адресат получает письмо, только
     *                              если подписан на этот тип. null — раздел без градации.
     */
    public function __construct(
        public string $section,
        public int $ownerUserId,
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $entityLabel = null,
        public array $rows = [],
        public ?string $event = null,
    ) {}
}
