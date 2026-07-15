<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\EntitySubscription;
use App\Services\Subscriptions\SubscriptionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Универсальный CRUD подписок на изменения сущностей раздела кабинета.
 *
 * Один контроллер обслуживает любой раздел из App\Services\Subscriptions\
 * SubscriptionRegistry — фронт (SubscriptionPanel) передаёт ключ раздела в
 * URL. Сейчас поддержан канал email; telegram появится отдельной фазой.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionRegistry $registry,
    ) {}

    /**
     * Список подписок текущего пользователя по разделу.
     */
    public function index(Request $request, string $section): JsonResponse
    {
        $this->ensureSection($section);

        $subscriptions = EntitySubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('section', $section)
            ->orderByDesc('id')
            ->get(['id', 'section', 'channel', 'destination', 'is_active', 'created_at']);

        return response()->json(['data' => $subscriptions]);
    }

    /**
     * Добавить email-подписку на изменения раздела.
     */
    public function store(Request $request, string $section): JsonResponse
    {
        $this->ensureSection($section);

        $data = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'email.required' => 'Укажите email-адрес.',
            'email.email' => 'Неверный формат email-адреса.',
        ])->validate();

        $email = mb_strtolower(trim($data['email']));

        $subscription = EntitySubscription::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'section' => $section,
                'channel' => 'email',
                'destination' => $email,
            ],
            [
                'is_active' => true,
            ],
        );

        // Реактивируем ранее отписанный адрес при повторном добавлении.
        if (! $subscription->is_active) {
            $subscription->update(['is_active' => true]);
        }

        return response()->json(['data' => $subscription->only([
            'id', 'section', 'channel', 'destination', 'is_active', 'created_at',
        ])], 201);
    }

    /**
     * Удалить подписку (только свою).
     */
    public function destroy(Request $request, EntitySubscription $subscription): JsonResponse
    {
        if ($subscription->user_id !== $request->user()->id) {
            abort(404);
        }

        $subscription->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Публичная отписка по токену из письма (без авторизации).
     */
    public function unsubscribe(string $token): \Illuminate\Http\Response
    {
        $subscription = EntitySubscription::query()
            ->where('unsubscribe_token', $token)
            ->first();

        if ($subscription && $subscription->is_active) {
            $subscription->update(['is_active' => false]);
        }

        $sectionLabel = $subscription
            ? $this->registry->label($subscription->section)
            : null;

        return response()->view('subscriptions.unsubscribed', [
            'found' => (bool) $subscription,
            'sectionLabel' => $sectionLabel,
            'destination' => $subscription?->destination,
        ]);
    }

    private function ensureSection(string $section): void
    {
        if (! $this->registry->exists($section)) {
            abort(404);
        }
    }
}
