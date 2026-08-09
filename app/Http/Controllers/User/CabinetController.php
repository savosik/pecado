<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ContractorBalance;
use App\Models\Order;
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

        // Агрегируем баланс по всем контрагентам пользователя
        $balances = $financeEnabled
            ? ContractorBalance::where('user_id', $user->id)->get()
            : collect();
        $totalBalance = $balances->sum('current_balance');
        $totalOverdue = $balances->sum('overdue_debt');
        $hasBalance = $balances->count() > 0;

        $balanceByOrganization = $financeEnabled ? $this->balanceByOrganization($user) : [];

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
            'balance' => $hasBalance ? [
                'current_balance' => $totalBalance,
                'overdue_debt' => $totalOverdue,
                'contractors_count' => $balances->count(),
                // v15.8.0: разрез по нашим юрлицам. Пустой массив — блок не показываем
                // вовсе: «одна организация: не указана» выглядит как поломка.
                'organizations' => $balanceByOrganization,
            ] : null,
            'recentOrders' => $recentOrders,
            'questionnaireCompleted' => $questionnaire && $questionnaire->isCompleted(),
            'clientStatus' => $user->clientStatus ? [
                'name' => $user->clientStatus->name,
                'color' => $user->clientStatus->color,
                'image_url' => $user->clientStatus->getFirstMediaUrl('image'),
            ] : null,
            'personalManager' => $user->personalManager ? [
                'name' => $user->personalManager->name,
                'phone' => $user->personalManager->phone,
                'email' => $user->personalManager->email,
                'photo_url' => $user->personalManager->getFirstMediaUrl('photo'),
            ] : null,
        ]);
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

        return \App\Models\ContractorOrganizationBalance::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('current_balance', '!=', 0)->orWhere('overdue_debt', '!=', 0))
            ->with(['organization', 'company:id,name'])
            ->get()
            ->filter(fn ($row) => $row->organization && ! $row->organization->is_stub)
            ->map(fn ($row) => [
                'organization_name' => $row->organization->name,
                'contractor_name' => $row->company?->name,
                'current_balance' => $row->current_balance,
                'overdue_debt' => $row->overdue_debt,
                'requisites' => array_filter([
                    'legal_name' => $row->organization->legal_name,
                    'tax_id' => $row->organization->tax_id,
                    'tax_code' => $row->organization->tax_code,
                    'bank_name' => $row->organization->bank_name,
                    'bank_bik' => $row->organization->bank_bik,
                    'account_number' => $row->organization->account_number,
                    'correspondent_account' => $row->organization->correspondent_account,
                ]),
            ])
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
