<?php

namespace App\Http\Controllers\Crm;

use App\Models\CrmEmail;
use App\Models\NotificationSuppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Стоп-лист адресов — настройка внутри «Писем», а не отдельный раздел.
 *
 * Отвечает ровно на один вопрос: почему на этот адрес не уходят письма.
 * Адрес попадает сюда двумя путями — человек отписался по ссылке из письма
 * или почтовый сервер отверг адрес как несуществующий. Третий путь —
 * менеджер вносит руками, когда клиент попросил «нам больше не пишите».
 */
class MailSuppressionController extends CrmController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CrmEmail::class);

        $rows = NotificationSuppression::query()
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(fn (NotificationSuppression $item): array => [
                'id' => (int) $item->getKey(),
                'email' => $item->email,
                'scope' => $item->scope,
                'scope_label' => $this->scopeLabel($item->scope),
                'reason' => $item->reason,
                'reason_label' => $this->reasonLabel($item->reason),
                'note' => $item->note,
                'created_at_label' => $item->created_at?->format('d.m.Y H:i'),
                'expires_at_label' => $item->expires_at?->format('d.m.Y'),
            ])
            ->all();

        return Inertia::render('Crm/Pages/Emails/Suppressions', [
            'suppressions' => $rows,
            'canManage' => $this->crmActor($request)->can('crm-emails.edit'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CrmEmail::class);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'email.required' => 'Укажите адрес.',
            'email.email' => 'Это не похоже на адрес электронной почты.',
            'note.max' => 'Пояснение длиннее 500 символов не поместится.',
        ]);

        NotificationSuppression::updateOrCreate(
            [
                'email' => mb_strtolower(trim($validated['email'])),
                'scope' => NotificationSuppression::SCOPE_ALL,
            ],
            [
                'reason' => NotificationSuppression::REASON_MANUAL,
                'note' => $validated['note'] ?? null,
            ],
        );

        return response()->json(['message' => 'Адрес добавлен в стоп-лист']);
    }

    public function destroy(Request $request, NotificationSuppression $suppression): JsonResponse
    {
        Gate::authorize('viewAny', CrmEmail::class);

        $suppression->delete();

        return response()->json(['deleted' => true]);
    }

    private function scopeLabel(string $scope): string
    {
        return match ($scope) {
            NotificationSuppression::SCOPE_ALL => 'Никаких писем',
            NotificationSuppression::SCOPE_MARKETING => 'Только реклама',
            default => 'Повод: '.$scope,
        };
    }

    private function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            NotificationSuppression::REASON_UNSUBSCRIBED => 'Отписался по ссылке',
            NotificationSuppression::REASON_BOUNCE => 'Почтовый сервер отверг адрес',
            NotificationSuppression::REASON_COMPLAINT => 'Пожаловался на спам',
            NotificationSuppression::REASON_MANUAL => 'Внёс сотрудник',
            default => '—',
        };
    }
}
