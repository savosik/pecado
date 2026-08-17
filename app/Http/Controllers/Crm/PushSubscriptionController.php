<?php

namespace App\Http\Controllers\Crm;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Подписка браузера менеджера на push-уведомления по задачам.
 *
 * Разрешение браузера запрашивает фронт и только по клику на тумблер:
 * авто-запрос при входе — верный способ получить вечное «Блокировать».
 */
class PushSubscriptionController extends CrmController
{
    /**
     * Состояние фичи для UI: включена ли, публичный VAPID-ключ, есть ли подписка.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $this->crmActor($request);

        $enabled = (bool) config('crm.push.enabled') && (string) config('webpush.vapid.public_key') !== '';

        return response()->json([
            'enabled' => $enabled,
            'public_key' => $enabled ? config('webpush.vapid.public_key') : null,
            'subscribed' => $enabled && $user->pushSubscriptions()->exists(),
        ]);
    }

    /**
     * Сохранить подписку браузера. Несколько браузеров = несколько подписок.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->crmActor($request);
        abort_unless((bool) config('crm.push.enabled'), 404);

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'content_encoding' => ['nullable', 'string', 'max:20'],
        ]);

        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? 'aes128gcm',
        );

        return response()->json(['subscribed' => true], 201);
    }

    /**
     * Отписать текущий браузер (по endpoint) или все браузеры пользователя.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $this->crmActor($request);

        $validated = $request->validate([
            'endpoint' => ['nullable', 'string', 'max:500'],
        ]);

        if (($validated['endpoint'] ?? '') !== '') {
            $user->deletePushSubscription($validated['endpoint']);
        } else {
            $user->pushSubscriptions()->delete();
        }

        return response()->json(['subscribed' => $user->pushSubscriptions()->exists()]);
    }
}
