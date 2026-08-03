<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Скоуп «только документы клиентов» для заказов и реализаций.
 *
 * Учётки сотрудников и служебные аккаунты тоже умеют оформлять заказы, и среди
 * этих заказов бывают тестовые — в выручке и среднем чеке им не место.
 *
 * Документы без user_id из выборки НЕ выпадают: это партнёрские заказы,
 * пришедшие из 1С без привязки к пользователю сайта. Покупатель у них
 * настоящий, деньги настоящие, и терять их в отчётах нельзя.
 */
trait FiltersClientDocuments
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfClients(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->where(fn (Builder $inner) => $inner
            ->whereNull($table.'.user_id')
            ->orWhereIn($table.'.user_id', User::query()->clients()->select('users.id')));
    }
}
