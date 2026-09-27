<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserQuestionRequest;
use App\Services\Support\UserQuestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class UserQuestionController extends Controller
{
    public function store(StoreUserQuestionRequest $request): RedirectResponse
    {
        $user = Auth::user();

        $email = $user?->email ?? (string) $request->input('email');
        $name = $user?->name ?? $request->input('name');

        app(UserQuestionService::class)->create($user, [
            'subject' => (string) $request->string('subject'),
            'body' => (string) $request->string('body'),
            'email' => $email,
            'name' => $name,
        ], [
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'source' => 'web',
        ], $request->hasFile('file') ? $request->file('file') : null);

        return back()
            ->with('success', 'Ваш вопрос отправлен. Мы ответим в течение 1 рабочего дня.');
    }
}
