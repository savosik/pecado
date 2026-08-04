<?php

namespace App\Http\Controllers\Crm;

use App\Models\CrmAgentToken;
use App\Models\User;
use App\Services\Crm\CrmTaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Токены ИИ-агентов: выдача и отзыв руководителем отдела.
 *
 * У аналитических токенов интерфейса нет — только команда в контейнере, то есть
 * каждая выдача требует разработчика. Для пишущего доступа это тем более неудобно:
 * отзыв бывает срочным (уволился сотрудник, утёк токен) и ждать не может.
 */
class AgentTokenController extends CrmController
{
    public function index(Request $request, CrmTaskService $tasks): Response
    {
        return Inertia::render('Crm/Pages/AgentTokens/Index', [
            'tokens' => $this->payload(),
            // Кандидаты — только сотрудники с доступом в CRM: агент работает
            // правами своего сотрудника, и токен на кладовщика не дал бы ничего.
            'users' => $tasks->assignableUsers(),
            'endpoints' => [
                'mcp' => url('/mcp/crm'),
                'rest' => url('/api/crm/me'),
                'docs' => url('/docs/crm-api'),
            ],
            'canCreate' => $request->user()?->can('crm-agent-tokens.create') ?? false,
            'canRevoke' => $request->user()?->can('crm-agent-tokens.delete') ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ], [
            'name.required' => 'Укажите, кому выдан токен.',
            'name.max' => 'Название не может быть длиннее 120 символов.',
            'user_id.required' => 'Выберите сотрудника.',
            'user_id.exists' => 'Такого сотрудника нет.',
        ], [
            'name' => 'название',
            'user_id' => 'сотрудник',
        ]);

        $user = User::findOrFail($validated['user_id']);

        if (! $user->hasCrmAccess()) {
            return back()->withErrors([
                'user_id' => 'У этого сотрудника нет доступа в CRM — агенту нечего было бы делать его правами.',
            ]);
        }

        CrmAgentToken::issue($validated['name'], (int) $user->getKey());

        return back()->with('success', "Токен для «{$user->name}» выпущен");
    }

    /**
     * Отзыв — флаг, а не удаление: кто и когда имел пишущий доступ, должно
     * оставаться видимым после его закрытия.
     */
    public function destroy(int $token): RedirectResponse
    {
        $model = CrmAgentToken::findOrFail($token);
        $model->forceFill(['is_active' => false])->save();

        return back()->with('success', "Токен «{$model->name}» отозван — доступ закрыт");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payload(): array
    {
        return CrmAgentToken::with('user:id,name')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CrmAgentToken $token): array => [
                'id' => (int) $token->getKey(),
                'name' => $token->name,
                'user' => $token->user?->name,
                'user_id' => (int) $token->user_id,
                // Токен показывается в списке всегда, а не единожды при создании:
                // он хранится открытым текстом, и прятать его в интерфейсе,
                // оставляя доступным в базе, было бы имитацией безопасности.
                'token' => $token->token,
                'is_active' => (bool) $token->is_active,
                'last_used_at' => $token->last_used_at?->format('d.m.Y H:i'),
                'created_at' => $token->created_at?->format('d.m.Y'),
            ])
            ->all();
    }
}
