<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
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

        $ordersCount = Order::where('user_id', $user->id)->count();
        $favoritesCount = $user->favorites()->count();
        $cartsCount = $user->carts()->count();

        $recentOrders = Order::where('user_id', $user->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($order) => [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'status'       => $order->status,
                'total'        => $order->total,
                'created_at'   => $order->created_at->format('d.m.Y'),
            ]);

        return Inertia::render('User/Cabinet/Dashboard', [
            'ordersCount'    => $ordersCount,
            'favoritesCount' => $favoritesCount,
            'cartsCount'     => $cartsCount,
            'recentOrders'   => $recentOrders,
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
            'name'       => ['required', 'string', 'max:255'],
            'surname'    => ['nullable', 'string', 'max:255'],
            'patronymic' => ['nullable', 'string', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:30'],
            'email'      => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
        ], [
            'name.required'  => 'Имя обязательно для заполнения.',
            'name.max'       => 'Имя не должно превышать 255 символов.',
            'email.required' => 'Email обязателен для заполнения.',
            'email.email'    => 'Введите корректный email.',
            'email.unique'   => 'Этот email уже используется другим пользователем.',
            'phone.max'      => 'Телефон не должен превышать 30 символов.',
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
            'password'         => ['required', 'string', Password::min(8), 'confirmed'],
        ], [
            'current_password.required' => 'Введите текущий пароль.',
            'password.required'         => 'Введите новый пароль.',
            'password.min'              => 'Пароль должен содержать минимум 8 символов.',
            'password.confirmed'        => 'Пароли не совпадают.',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors([
                'current_password' => 'Текущий пароль введён неверно.',
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('success', 'Пароль успешно изменён.');
    }
}
