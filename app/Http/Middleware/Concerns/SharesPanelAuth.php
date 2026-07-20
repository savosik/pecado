<?php

namespace App\Http\Middleware\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Общая форма Inertia-пропов для панелей (/admin и /crm).
 *
 * Форма auth.user — контракт, на который опираются usePermission, PageHeader
 * и createActionsColumn. Если панели соберут его по-разному, can() на фронте
 * молча вернёт false: кнопки исчезнут без единой ошибки. Поэтому — одно место.
 */
trait SharesPanelAuth
{
    /**
     * @return array{user: array<string, mixed>|null}
     */
    protected function panelAuthProps(?User $user): array
    {
        return [
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->hasRole('super-admin'),
                // Суперадмину отдаём '*' вместо списка из ~170 строк.
                'permissions' => $user->hasRole('super-admin')
                    ? ['*']
                    : $user->getAllPermissions()->pluck('name')->toArray(),
                'roles' => $user->getRoleNames()->toArray(),
                'is_admin' => $user->hasAdminAccess(),
                'is_crm' => $user->hasCrmAccess(),
                'is_wms' => $user->hasWmsAccess(),
            ] : null,
        ];
    }

    /**
     * @return array<string, \Closure>
     */
    protected function panelFlashProps(Request $request): array
    {
        return [
            'success' => fn () => $request->session()->get('success'),
            'error' => fn () => $request->session()->get('error'),
            'warning' => fn () => $request->session()->get('warning'),
            'info' => fn () => $request->session()->get('info'),
        ];
    }
}
