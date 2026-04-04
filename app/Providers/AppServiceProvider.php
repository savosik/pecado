<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserCreated::class,
            \App\Listeners\PublishUserToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserUpdated::class,
            \App\Listeners\PublishUserToErp::class,
        );

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
    }
}
