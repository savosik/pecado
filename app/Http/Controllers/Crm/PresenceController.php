<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Кто из партнёров сейчас на сайте.
 *
 * Вебсокетов в проекте нет вовсе: `BROADCAST_CONNECTION=log`, Reverb и Echo
 * не установлены, `routes/channels.php` не существует. Ставить их ради тонкой
 * полоски в шапке — новый сервис в compose, каналы, авторизация и новая точка
 * отказа, поэтому присутствие строится опросом `users.last_seen_at`.
 *
 * Точность задаёт не этот контроллер, а шаг трекинга в
 * {@see \App\Http\Middleware\TrackUserLastSeen}: окно «сейчас» не может быть
 * меньше него, иначе полоска будет мигать на ровном месте.
 *
 * Присутствие подчиняется разрезу «только мои»: иначе менеджер видел бы
 * в шапке партнёров, которых нет в его списке.
 */
class PresenceController extends CrmController
{
    public function __invoke(Request $request): JsonResponse
    {
        $limit = max(1, (int) config('crm.presence.limit', 7));
        $windowSeconds = max(60, (int) config('crm.presence.window_seconds', 300));
        $since = now()->subSeconds($windowSeconds);

        $query = User::query()
            ->inCrmScope($this->crmActor($request), CrmScope::fromRequest($request, $this->crmActor($request)))
            ->where('last_seen_at', '>=', $since);

        // Считаем до среза: «+N» должно говорить, сколько людей не поместилось,
        // а не сколько строк вернул запрос.
        $total = (clone $query)->count();

        $clients = $query
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get(['id', 'name', 'erp_name', 'last_seen_at']);

        return response()->json([
            'total' => $total,
            'poll_seconds' => max(15, (int) config('crm.presence.poll_seconds', 45)),
            'clients' => $clients->map(fn (User $client): array => [
                'id' => (int) $client->getKey(),
                'name' => $client->display_name,
                'seen_ago_seconds' => $client->last_seen_at === null
                    ? null
                    : (int) $client->last_seen_at->diffInSeconds(now()),
            ])->all(),
        ]);
    }
}
