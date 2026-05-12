<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\Pricing\PriceServiceInterface::class,
            \App\Services\Pricing\PriceService::class
        );

        $this->app->bind(
            \App\Contracts\Currency\CurrencyConversionServiceInterface::class,
            \App\Services\Currency\CurrencyConversionService::class
        );

        $this->app->bind(
            \App\Contracts\Currency\UserCurrencyResolverInterface::class,
            \App\Services\Currency\UserCurrencyResolver::class
        );

        $this->app->bind(
            \App\Contracts\Stock\StockServiceInterface::class,
            \App\Services\Stock\StockService::class
        );

        $this->app->bind(
            \App\Contracts\Cart\CartServiceInterface::class,
            \App\Services\Cart\CartService::class
        );

        $this->app->bind(
            \App\Contracts\Order\CheckoutServiceInterface::class,
            \App\Services\Order\CheckoutService::class
        );

        $this->app->bind(
            \App\Contracts\Order\OrderRepositoryInterface::class,
            \App\Repositories\OrderRepository::class
        );

        $this->app->singleton(\App\Services\Product\RichContent\BlockSchemaProvider::class, function () {
            return new \App\Services\Product\RichContent\BlockSchemaProvider(
                (string) config('rich_content.schema_path', base_path('docs/content-blocks-schema.json'))
            );
        });

        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super-admin bypass — role «super-admin» получает все права автоматически
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });

        Horizon::auth(function ($request) {
            return app()->environment('local') ||
                ($request->user() && $request->user()->hasRole('super-admin'));
        });

        \Illuminate\Support\Facades\Gate::define('viewPulse', function ($user) {
            return app()->environment('local') || $user->hasRole('super-admin');
        });

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserCreated::class,
            \App\Listeners\PublishUserToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserUpdated::class,
            \App\Listeners\PublishUserToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\CompanyCreated::class,
            \App\Listeners\PublishContractorToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\CompanyUpdated::class,
            \App\Listeners\PublishContractorToErp::class,
        );

        \App\Models\CompanyBankAccount::observe(\App\Observers\CompanyBankAccountObserver::class);

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderCreated::class,
            \App\Listeners\PublishOrderToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderUpdated::class,
            \App\Listeners\PublishOrderToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderDeleted::class,
            \App\Listeners\PublishOrderToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\ReturnCreated::class,
            \App\Listeners\PublishReturnToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\ReturnCreated::class,
            \App\Listeners\SendReturnCreatedEmail::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\ReturnStatusChanged::class,
            \App\Listeners\SendReturnStatusChangedEmail::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserRegisteredOnSite::class,
            \App\Listeners\SendWelcomeEmail::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserPasswordChanged::class,
            \App\Listeners\SendPasswordChangedEmail::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderCreated::class,
            \App\Listeners\SendOrderCreatedEmail::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderCreated::class,
            \App\Listeners\NotifyManagersAboutNewOrder::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderUpdated::class,
            \App\Listeners\SendOrderStatusChangedEmail::class,
        );

        // Rate limiter для Content API (ИИ-агент)
        RateLimiter::for('content-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Scramble: документировать только /api/content/* роуты
        Scramble::registerApi('content', [
            'api_path' => 'api/content',
            'info' => [
                'title' => 'Pecado Content API',
                'version' => '1.0',
                'description' => 'API для ИИ-агента контент-менеджера. Авторизация через Bearer Token (Sanctum).',
            ],
        ])->afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer', 'bearer')
            );
        });

        Scramble::registerUiRoute(path: 'docs/api', api: 'content');
        Scramble::registerJsonSpecificationRoute(path: 'docs/api.json', api: 'content');

        // Scramble: Kanban API — для LLM-агентов
        Scramble::registerApi('kanban', [
            'api_path' => 'api/kanban',
            'middleware' => ['web'],
            'info' => [
                'title' => 'Pecado Kanban API',
                'version' => '1.0',
                'description' => implode("\n\n", [
                    'Публичный API управления задачами канбан-доски.',
                    '**Авторизация не требуется.** API активируется переменной окружения `KANBAN_API_ENABLED=true`.',
                    '',
                    '## Основные сценарии для LLM-агентов',
                    '',
                    '1. **Получить задачи** — `GET /api/kanban/tasks?status=backlog`',
                    '2. **Взять задачу в работу** — `PATCH /api/kanban/tasks/{id}` с `{"status": "in_progress"}`',
                    '3. **Оставить комментарий** — `POST /api/kanban/tasks/{id}/comments`',
                    '4. **Закрыть задачу** — `PATCH /api/kanban/tasks/{id}` с `{"status": "done"}`',
                    '',
                    '## Доступные статусы',
                    '| Статус | Описание |',
                    '|--------|----------|',
                    '| `backlog` | Новые задачи, не распределённые в спринт |',
                    '| `todo` | К выполнению в текущем спринте |',
                    '| `in_progress` | В работе |',
                    '| `testing` | На тестировании |',
                    '| `done` | Выполнено |',
                    '| `reopen` | Открыто повторно |',
                    '',
                    '## Доступные типы',
                    '| Тип | Описание |',
                    '|-----|----------|',
                    '| `bug` | Ошибка в работе системы |',
                    '| `unexpected` | Странное поведение |',
                    '| `ux` | Неудобство в использовании |',
                    '| `cosmetic` | Визуальная проблема |',
                    '| `feature` | Новая функциональность |',
                    '| `improvement` | Улучшение существующего |',
                ]),
            ],
        ]);

        Scramble::registerUiRoute(path: 'docs/kanban-api', api: 'kanban');
        Scramble::registerJsonSpecificationRoute(path: 'docs/kanban-api.json', api: 'kanban');
    }
}
