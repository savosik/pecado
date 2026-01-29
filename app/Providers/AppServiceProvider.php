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
        //
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
