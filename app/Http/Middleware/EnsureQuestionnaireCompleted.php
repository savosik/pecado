<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureQuestionnaireCompleted
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user || $user->is_admin) {
            return $next($request);
        }

        // Если у пользователя нет завершённой анкеты — редирект на onboarding
        $questionnaire = $user->questionnaire;
        if (!$questionnaire || !$questionnaire->isCompleted()) {
            // Не редиректим если уже на onboarding, logout или API
            $path = $request->path();
            $excluded = ['onboarding', 'logout'];

            foreach ($excluded as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return $next($request);
                }
            }

            return redirect('/onboarding');
        }

        return $next($request);
    }
}
