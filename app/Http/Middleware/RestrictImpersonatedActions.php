<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Что менеджеру нельзя, пока он смотрит сайт от имени партнёра.
 *
 * Режим задуман как «посмотреть глазами партнёра»: витрина, цены, корзина,
 * кабинет — всё доступно. Запрещено ровно то, что оставляет за партнёром след
 * во внешнем мире: документы (уезжают в 1С) и его доступы.
 *
 * Проверка серверная и по имени маршрута, а не по спрятанным кнопкам: скрытая
 * кнопка не мешает отправить тот же запрос руками, а список ниже — мешает.
 */
class RestrictImpersonatedActions
{
    /**
     * Маршруты, закрытые в режиме просмотра.
     *
     * @var array<int, string>
     */
    private const BLOCKED_ROUTES = [
        // Оформление заказа — прямой запрет заказчика: заказ уезжает в 1С
        // и становится обязательством партнёра, которого он не давал.
        'checkout.store',
        // Возврат — такой же документ от имени партнёра.
        'cabinet.returns.store',
        // Обращение в поддержку: уходит письмом сотрудникам как вопрос партнёра.
        'faq.questions.store',
        // Смена пароля закрыла бы партнёру вход на сайт.
        'cabinet.password.update',
        // Ключи интеграции партнёра: выдача и отзыв ломают его выгрузки.
        'cabinet.api-tokens.store',
        'cabinet.api-tokens.regenerate',
        'cabinet.api-tokens.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! Impersonation::active()) {
            return $next($request);
        }

        if (! $request->routeIs(self::BLOCKED_ROUTES)) {
            return $next($request);
        }

        $message = 'Просмотр от имени партнёра: оформлять документы и менять его доступы нельзя.';

        // Inertia шлёт Accept: application/json, поэтому отличаем настоящий
        // JSON-запрос по отсутствию X-Inertia — как в bootstrap/app.php.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        return back()->with('error', $message);
    }
}
