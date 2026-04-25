<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast для аудит-меток 1С (`orders.erp_*`, `shipments.erp_*` и т.п.).
 *
 * При записи нормализует любую входящую TZ к `app.timezone` (на проекте
 * установлено `Europe/Moscow`), чтобы стенограмма в БД и в админке
 * совпадала с тем, что менеджер видит в 1С — независимо от того, какой
 * суффикс пришёл от ERP (`+03:00`, `Z`, `+05:00` и т.д.).
 */
class ErpDatetime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, config('app.timezone'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }
}
