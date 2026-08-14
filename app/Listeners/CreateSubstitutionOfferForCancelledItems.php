<?php

namespace App\Listeners;

use App\Events\OrderItemsCancelled;
use App\Services\Substitution\SubstitutionOfferService;

/**
 * Отмена строк в 1С → подборка замен и задача менеджеру.
 *
 * Синхронный слушатель: событие уже диспатчится после коммита обработчика
 * шины, а создание оффера с задачей — две быстрые записи. Очередь здесь
 * добавила бы только окно, в котором недобор невидим.
 */
class CreateSubstitutionOfferForCancelledItems
{
    public function __construct(
        private readonly SubstitutionOfferService $offers,
    ) {}

    public function handle(OrderItemsCancelled $event): void
    {
        // Главный флаг контура замен: выключен — поведение системы не меняется вовсе.
        if (! config('substitutions.enabled')) {
            return;
        }

        $this->offers->registerCancellation($event->order, $event->orderItemIds);
    }
}
