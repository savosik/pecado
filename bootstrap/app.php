<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], base_path('routes/admin.php'));
            Route::group([], base_path('routes/crm.php'));
            Route::group([], base_path('routes/wms.php'));
        },
    )
    // 🐛 Автообнаружение листенеров отключено намеренно.
    //
    // Laravel 11+ сам находит листенеры в app/Listeners по типу аргумента handle(),
    // а проект вдобавок регистрирует их явно в AppServiceProvider::boot(). Получались
    // две регистрации одного листенера — и клиент получал каждое письмо о заказе
    // дважды (`php artisan event:list` показывал пары `X` и `X@handle`).
    //
    // Выбрана явная регистрация: связь событие→листенер читается в одном месте,
    // а листенеры с union-типом в handle() (PublishOrderToErp) автообнаружением
    // не подхватываются вовсе — то есть полагаться на него всё равно нельзя.
    //
    // ⚠️ Новый листенер теперь ОБЯЗАН быть зарегистрирован в AppServiceProvider::boot(),
    // иначе он просто не сработает.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // Доверяем reverse-proxy (nginx на хосте) — нужно для корректного определения HTTPS
        $middleware->trustProxies(at: '*');

        $middleware->statefulApi();

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnsureUserIsNotBlocked::class,
        ]);

        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'apiship.webhook' => \App\Http\Middleware\ApiShipWebhookEnabled::class,
        ]);

        $middleware->group('admin', [
            \App\Http\Middleware\EnsureUserIsAdmin::class,
            \App\Http\Middleware\HandleAdminInertiaRequests::class,
        ]);

        $middleware->group('crm', [
            \App\Http\Middleware\EnsureUserIsCrm::class,
            \App\Http\Middleware\HandleCrmInertiaRequests::class,
        ]);

        $middleware->group('wms', [
            \App\Http\Middleware\EnsureUserIsWms::class,
            \App\Http\Middleware\HandleWmsInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (in_array($response->getStatusCode(), [500, 503, 404, 403])) {
                // API-маршруты и AJAX-запросы — возвращаем JSON, а не Inertia-страницу.
                // Inertia-запросы тоже шлют Accept: application/json, поэтому отличаем
                // их по заголовку X-Inertia — иначе пользователю бы показывался iframe
                // с сырым JSON-ответом вместо нашей страницы ошибки.
                if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                    return response()->json([
                        'message' => match ($response->getStatusCode()) {
                            404 => 'Не найдено',
                            403 => 'Доступ запрещён',
                            503 => 'Сервис недоступен',
                            default => 'Ошибка сервера',
                        },
                    ], $response->getStatusCode());
                }

                return Inertia::render('ErrorPage', ['status' => $response->getStatusCode()])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            if ($response->getStatusCode() === 419) {
                return back()->with([
                    'error' => 'Сессия истекла, попробуйте ещё раз.',
                ]);
            }

            return $response;
        });
    })->create();
