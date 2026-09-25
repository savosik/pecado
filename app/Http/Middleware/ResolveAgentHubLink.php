<?php

namespace App\Http\Middleware;

use App\Models\AgentHubLink;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ к пульту Agent Hub по ссылке-хешу.
 *
 * Токен — единственный ключ: находим по нему живую ссылку, отозванная и
 * несуществующая одинаково дают 404 (не подсказываем, что ссылка была).
 * Найденную кладём в атрибуты запроса — контроллеры берут её оттуда.
 */
class ResolveAgentHubLink
{
    /** Ключ атрибута запроса, под которым лежит ссылка. */
    public const ATTRIBUTE = 'agent_hub_link';

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $link = AgentHubLink::query()->active()->where('token', $token)->first();

        abort_if(! $link, 404, 'Ссылка не найдена или отозвана.');

        $link->touchUsage();
        $request->attributes->set(self::ATTRIBUTE, $link);

        $response = $next($request);

        // Пульт открыт без авторизации — в индекс поисковиков ему не надо.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
