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
            $redirectTo = $user->roles->isNotEmpty() ? '/admin' : '/';

            // Если intended URL — API-маршрут, сбрасываем, чтобы не отдавать JSON вместо Inertia-страницы
            $intended = $request->session()->pull('url.intended', $redirectTo);
            if (str_starts_with(parse_url($intended, PHP_URL_PATH) ?? '', '/api/')) {
                $intended = $redirectTo;
            }

            return redirect()->to($intended)->with('success', 'Вы успешно вошли в систему');
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
            'name' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country' => 'nullable|string|in:RU,BY,KZ',
            'city' => 'nullable|string|max:255',
            'password' => 'required|string|min:8|confirmed',
            'terms_accepted' => 'nullable|boolean',
        ], [
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email',
            'email.unique' => 'Пользователь с таким email уже зарегистрирован',
            'password.required' => 'Пароль обязателен для заполнения',
            'password.min' => 'Пароль должен содержать не менее 8 символов',
            'password.confirmed' => 'Пароли не совпадают',
            'terms_accepted.accepted' => 'Необходимо принять условия использования',
        ]);

        $user = User::create([
            'name' => $validated['name'] ?? null,
            'email' => strtolower($validated['email']),
            'phone' => $validated['phone'] ?? null,
            'country' => $validated['country'] ?? null,
            'city' => $validated['city'] ?? null,
            'password' => $validated['password'],
            'terms_accepted' => true,
            // 'is_admin' removed — roles are assigned separately
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/onboarding')->with('success', 'Регистрация прошла успешно! Расскажите нам о вашем бизнесе.');
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
