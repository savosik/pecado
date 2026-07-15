<?php

namespace App\Events;

use App\Subscriptions\EntityChangeNotice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Универсальное событие «сущность раздела кабинета изменилась».
 *
 * Диспатчится из точек фиксации изменений (напр. создание OrderChangeLog).
 * Слушает App\Listeners\SendEntitySubscriptionNotifications, который находит
 * подписки владельца по разделу и рассылает уведомления.
 */
class EntityChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public EntityChangeNotice $notice,
    ) {}
}
