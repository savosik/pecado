<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class AuthController extends Controller
{
    /**
     * Display the login form.
     */
    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle login request.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ], [
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email',
            'password.required' => 'Пароль обязателен для заполнения',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            /** @var User $user */
            $user = Auth::user();

            // Админы — в админку, обычные пользователи — на главную
            $redirectTo = $user->is_admin ? '/admin' : '/';

            return redirect()->intended($redirectTo)->with('success', 'Вы успешно вошли в систему');
        }

        return back()->withErrors([
            'email' => 'Неверный email или пароль',
        ])->onlyInput('email');
    }

    /**
     * Display the registration form.
     */
    public function showRegister(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle registration request.
     */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'surname' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'patronymic' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20',
            'country' => 'required|string|in:RU,BY,KZ',
            'city' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
            'terms_accepted' => 'accepted',
        ], [
            'surname.required' => 'Фамилия обязательна для заполнения',
            'name.required' => 'Имя обязательно для заполнения',
            'patronymic.required' => 'Отчество обязательно для заполнения',
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email',
            'email.unique' => 'Пользователь с таким email уже зарегистрирован',
            'phone.required' => 'Телефон обязателен для заполнения',
            'country.required' => 'Выберите страну',
            'country.in' => 'Выберите корректную страну',
            'city.required' => 'Город обязателен для заполнения',
            'password.required' => 'Пароль обязателен для заполнения',
            'password.min' => 'Пароль должен содержать не менее 8 символов',
            'password.confirmed' => 'Пароли не совпадают',
            'terms_accepted.accepted' => 'Необходимо принять условия использования',
        ]);

        $user = User::create([
            'surname' => $validated['surname'],
            'name' => $validated['name'],
            'patronymic' => $validated['patronymic'],
            'email' => strtolower($validated['email']),
            'phone' => $validated['phone'],
            'country' => $validated['country'],
            'city' => $validated['city'],
            'password' => $validated['password'],
            'terms_accepted' => true,
            'is_admin' => false,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/')->with('success', 'Регистрация прошла успешно! Добро пожаловать!');
    }

    /**
     * Handle logout request.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('success', 'Вы вышли из системы');
    }
}
