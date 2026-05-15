<?php

namespace App\Services\ProductExport;

/**
 * Пресет, который умеет аккумулировать замеры этапов в StepTimer.
 *
 * Why отдельный интерфейс, а не метод в PresetInterface: основной интерфейс
 * описывает контракт «выгрузить товары в поток», а замеры — кросскат-беспокойство,
 * нужное только генератору для записи product_export_runs.steps_json. Сделано
 * необязательным: если пресет не умеет — Generator просто не передаёт таймер.
 */
interface TimerAware
{
    public function setStepTimer(?StepTimer $timer): void;
}
