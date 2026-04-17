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
        $user->load('clientStatus');

        $ordersCount = Order::where('user_id', $user->id)->count();
        $favoritesCount = $user->favorites()->count();
        $cartsCount = $user->carts()->count();

        // Агрегируем баланс по всем контрагентам пользователя
        $balances = ContractorBalance::where('user_id', $user->id)->get();
        $totalBalance = $balances->sum('current_balance');
        $totalOverdue = $balances->sum('overdue_debt');
        $hasBalance = $balances->count() > 0;

        $recentOrders = Order::where('user_id', $user->id)
            ->withCount('items')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->number,
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
            ] : null,
            'recentOrders' => $recentOrders,
            'questionnaireCompleted' => $questionnaire && $questionnaire->isCompleted(),
            'clientStatus' => $user->clientStatus ? [
                'name' => $user->clientStatus->name,
                'color' => $user->clientStatus->color,
                'image_url' => $user->clientStatus->getFirstMediaUrl('image'),
            ] : null,
        ]);
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

        return back()->with('success', 'Пароль успешно изменён.');
    }
}
