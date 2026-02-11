<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Supported OAuth providers.
     */
    private const ALLOWED_PROVIDERS = ['google', 'yandex', 'vkontakte', 'mailru'];

    /**
     * Redirect to social provider authentication page.
     */
    public function redirect(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle callback from social provider.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect('/login')->with('error', 'Не удалось авторизоваться через ' . $this->getProviderLabel($provider));
        }

        // Find or create user
        $user = $this->findOrCreateUser($socialUser, $provider);

        // Login user
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/')->with('success', 'Вы успешно вошли в систему');
    }

    /**
     * Validate that the provider is supported.
     */
    protected function validateProvider(string $provider): void
    {
        if (!in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            abort(404);
        }
    }

    /**
     * Find or create user from social account.
     */
    protected function findOrCreateUser(object $socialUser, string $provider): User
    {
        // Try to find existing social account
        $socialAccount = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($socialAccount) {
            // Update token and user info
            $socialAccount->update([
                'provider_token' => $socialUser->token,
                'provider_refresh_token' => $socialUser->refreshToken ?? null,
                'provider_avatar' => $socialUser->getAvatar(),
                'provider_name' => $socialUser->getName(),
                'provider_email' => $socialUser->getEmail(),
            ]);

            return $socialAccount->user;
        }

        // Check if user exists with same email
        $user = User::query()->where('email', $socialUser->getEmail())->first();

        if (!$user) {
            // Create new user
            $user = User::create([
                'name' => $socialUser->getName() ?? Str::before($socialUser->getEmail(), '@'),
                'email' => $socialUser->getEmail(),
                'password' => Hash::make(Str::random(32)),
                'is_admin' => false,
            ]);
        }

        // Create social account
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_id' => $socialUser->getId(),
            'provider_token' => $socialUser->token,
            'provider_refresh_token' => $socialUser->refreshToken ?? null,
            'provider_avatar' => $socialUser->getAvatar(),
            'provider_name' => $socialUser->getName(),
            'provider_email' => $socialUser->getEmail(),
        ]);

        return $user;
    }

    /**
     * Get human-readable provider name.
     */
    private function getProviderLabel(string $provider): string
    {
        return match ($provider) {
            'google' => 'Google',
            'yandex' => 'Яндекс',
            'vkontakte' => 'ВКонтакте',
            'mailru' => 'Mail.ru',
            default => $provider,
        };
    }
}
