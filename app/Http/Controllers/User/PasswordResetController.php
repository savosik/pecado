<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    /**
     * Display the forgot password form.
     */
    public function showForgotPassword(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Send a password reset link.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|string|email',
        ], [
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email',
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('success', 'Ссылка для сброса пароля отправлена на ваш email');
        }

        return back()->withErrors([
            'email' => $this->getStatusMessage($status),
        ])->onlyInput('email');
    }

    /**
     * Display the reset password form.
     */
    public function showResetPassword(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * Reset the password.
     */
    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|string|email',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email',
            'password.required' => 'Пароль обязателен для заполнения',
            'password.min' => 'Пароль должен содержать не менее 8 символов',
            'password.confirmed' => 'Пароли не совпадают',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect('/login')->with('success', 'Пароль успешно изменён! Войдите с новым паролем.');
        }

        return back()->withErrors([
            'email' => $this->getStatusMessage($status),
        ]);
    }

    /**
     * Get human-readable status message.
     */
    private function getStatusMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_USER => 'Пользователь с таким email не найден',
            Password::INVALID_TOKEN => 'Ссылка для сброса пароля недействительна или истекла',
            Password::RESET_THROTTLED => 'Слишком много попыток. Попробуйте позже',
            default => __($status),
        };
    }
}
