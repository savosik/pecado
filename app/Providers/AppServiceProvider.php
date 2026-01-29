<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
            \App\Listeners\PublishCompanyToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\CompanyUpdated::class,
            \App\Listeners\PublishCompanyToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\CompanyDeleted::class,
            \App\Listeners\PublishCompanyToErp::class,
        );
    }
}
