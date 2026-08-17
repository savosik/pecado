<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ContractorBalance;
use App\Models\Order;
use App\Services\Settlements\CabinetSettlementFinance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class CabinetController extends Controller
{
    public function dashboard()
    {
        $user = Auth::user();
        $questionnaire = $user->questionnaire;
        $user->load(['clientStatus', 'personalManager.media']);

        $ordersCount = Order::where('user_id', $user->id)->count();
        $favoritesCount = $user->favorites()->count();
        $cartsCount = $user->carts()->count();

        // Баланс закрыт флагом, пока цифры долга не сверены с 1С: показать клиенту
        // завышенную задолженность хуже, чем не показать никакой.
        $financeEnabled = (bool) config('cabinet.finance_enabled');

        // v16.0.0: на регистре главное число другое. Раньше показывали сальдо,
        // но в него входят обязательства, срок которых ещё не наступил, и клиент
        // читал их как «должен прямо сейчас». Теперь наверху — «к оплате сейчас»,
        // а сальдо остаётся справочным.
        $ledger = $financeEnabled && config('settlements.ledger_enabled')
            ? app(CabinetSettlementFinance::class)->summary($user)
            : null;

        // Агрегируем баланс по всем контрагентам пользователя
        $balances = $financeEnabled && $ledger === null
            ? ContractorBalance::where('user_id', $user->id)->get()
            : collect();
        $totalBalance = $balances->sum('current_balance');
        $totalOverdue = $balances->sum('overdue_debt');
        $hasBalance = $balances->count() > 0;

        $balanceByOrganization = $financeEnabled && $ledger === null ? $this->balanceByOrganization($user) : [];

        $recentOrders = Order::where('user_id', $user->id)
            ->withCount('items')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->erp_number ?? $order->number ?? ('#'.$order->id),
                'status' => $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status,
                'type' => $order->type instanceof \BackedEnum ? $order->type->value : (string) $order->type,
                'total' => $order->total_amount,
                'items_count' => $order->items_count,
                'created_at' => $order->created_at->format('d.m.Y'),
            ]);

        return Inertia::render('User/Cabinet/Dashboard', [
            'ordersCount' => $ordersCount,
            'favoritesCount' => $favoritesCount,
            'cartsCount' => $cartsCount,
            'balance' => $ledger ?? ($hasBalance ? [
                'current_balance' => $totalBalance,
                'overdue_debt' => $totalOverdue,
                'contractors_count' => $balances->count(),
                // v15.8.0: разрез по нашим юрлицам. Пустой массив — блок не показываем
                // вовсе: «одна организация: не указана» выглядит как поломка.
                'organizations' => $balanceByOrganization,
            ] : null),
            'recentOrders' => $recentOrders,
            'questionnaireCompleted' => $questionnaire && $questionnaire->isCompleted(),
            'clientStatus' => $user->clientStatus ? [
                'name' => $user->clientStatus->name,
                'color' => $user->clientStatus->color,
                'image_url' => $user->clientStatus->getFirstMediaUrl('image'),
            ] : null,
            'personalManager' => $this->personalManagerProp($user),
        ]);
    }

    /**
     * Карточка менеджера для дашборда кабинета с учётом замещения (abs-01).
     *
     * При активном отсутствии с замещающим клиент видит контакты замещающего
     * и пояснение, кого и до какой даты тот замещает. personal_manager_id
     * клиента не меняется — подмена только на чтении.
     *
     * @return array<string, mixed>|null
     */
    private function personalManagerProp(\App\Models\User $user): ?array
    {
        if (! $user->personalManager) {
            return null;
        }

        $resolution = app(\App\Services\Crm\ManagerAbsenceResolver::class)->resolve($user->personalManager);
        $manager = $resolution->manager->loadMissing('media');

        return [
            'name' => $manager->name,
            'phone' => $manager->phone,
            'email' => $manager->email,
            'photo_url' => $manager->getFirstMediaUrl('photo'),
            'substitution' => $resolution->isSubstitution() ? [
                'absent_manager_name' => $resolution->absentManager->name,
                'until' => $resolution->until->format('d.m.Y'),
            ] : null,
        ];
    }

    /**
     * Задолженность клиента в разрезе наших организаций (v15.8.0, org-06).
     *
     * Главная польза для клиента — не сама цифра, а реквизиты: он платит разным
     * юрлицам на разные счета, и должен видеть, куда именно.
     *
     * Возвращает пустой массив, когда флаг выключен, разрез ни разу не приходил
     * или все строки обнулены (долг погашен). Плюсы и минусы разных организаций
     * НЕ схлопываются в одно число: взаимозачёт между нашими юрлицами — компетенция
     * 1С, и «ноль» на экране убедил бы клиента, что платить нечего.
     *
     * @return array<int, array<string, mixed>>
     */
    private function balanceByOrganization(\App\Models\User $user): array
    {
        if (! config('erp.organizations.enabled')) {
            return [];
        }

        $rows = \App\Models\ContractorOrganizationBalance::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('current_balance', '!=', 0)->orWhere('overdue_debt', '!=', 0))
            ->with(['organization', 'company:id,name'])
            ->get()
            ->filter(fn ($row) => $row->organization && ! $row->organization->is_stub);

        // Разрез по юрлицам клиента показываем, только когда их несколько:
        // единственный контрагент в карточке — шум.
        $splitByCompany = $rows->pluck('company_id')->filter()->unique()->count() > 1;

        return $rows
            ->groupBy('organization_id')
            ->map(function ($group) use ($splitByCompany) {
                $contractors = $group
                    ->map(fn ($row) => [
                        'name' => $row->company?->name ?? 'Контрагент не указан',
                        'current_balance' => round((float) $row->current_balance, 2),
                        'overdue_debt' => round((float) $row->overdue_debt, 2),
                    ])
                    ->sortBy('name')
                    ->values();

                return \App\Services\Settlements\CabinetSettlementFinance::organizationCard(
                    $group->first()->organization,
                    $contractors,
                    $splitByCompany,
                );
            })
            ->sortBy('organization_name')
            ->values()
            ->all();
    }

    public function profile()
    {
        return Inertia::render('User/Cabinet/Profile');
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ], [
            'name.required' => 'Имя обязательно для заполнения.',
            'name.max' => 'Имя не должно превышать 255 символов.',
            'email.required' => 'Email обязателен для заполнения.',
            'email.email' => 'Введите корректный email.',
            'email.unique' => 'Этот email уже используется другим пользователем.',
            'phone.max' => 'Телефон не должен превышать 30 символов.',
        ]);

        $user->update($validated);

        return back()->with('success', 'Профиль успешно обновлён.');
    }

    public function changePassword()
    {
        return Inertia::render('User/Cabinet/ChangePassword');
    }

    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ], [
            'current_password.required' => 'Введите текущий пароль.',
            'password.required' => 'Введите новый пароль.',
            'password.min' => 'Пароль должен содержать минимум 8 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors([
                'current_password' => 'Текущий пароль введён неверно.',
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        \App\Events\UserPasswordChanged::dispatch($user, 'cabinet', $request->ip());

        return back()->with('success', 'Пароль успешно изменён.');
    }
}
