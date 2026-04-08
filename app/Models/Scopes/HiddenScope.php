<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * v10: Исключает товары с hidden = true из всех публичных запросов.
 * Применяется автоматически ко всем запросам Product::query().
 * В AdminController снимается через Product::withoutGlobalScope(HiddenScope::class).
 */
class HiddenScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('hidden', false);
    }
}
