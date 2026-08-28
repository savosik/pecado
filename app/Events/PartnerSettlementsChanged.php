<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * По партнёру пришли новые движения регистра или баланс из 1С.
 *
 * Бросают ERP-хендлеры после фиксации данных. Слушатель лестницы долга
 * пересчитывает ступень партнёра, но только вверх: оплата размораживает
 * мгновенно, ужесточение — только ночным пересчётом.
 *
 * @param  list<int>  $userIds
 */
class PartnerSettlementsChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(public readonly array $userIds, public readonly string $source) {}
}
