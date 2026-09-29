<?php

namespace App\Console\Commands;

use App\Services\Erp\Support\ScheduledPrices;
use Illuminate\Console\Command;

/**
 * Включение базовых цен с наступившей датой вступления в силу (v16.13.0).
 *
 * 1С присылает price.updated в момент проведения установки цен, а действует
 * цена с даты документа. Будущие цены лежат в erp_scheduled_prices, эта
 * команда раз в минуту переносит наступившие в карточку товара.
 */
class ActivateScheduledPrices extends Command
{
    protected $signature = 'erp:activate-scheduled-prices';

    protected $description = 'Включить отложенные базовые цены из 1С, у которых наступила дата вступления в силу';

    public function handle(ScheduledPrices $scheduled): int
    {
        $activated = $scheduled->activateDue();

        if ($activated > 0) {
            $this->info("Включены отложенные цены: {$activated} товар(ов).");
        }

        return self::SUCCESS;
    }
}
