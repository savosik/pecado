<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

if (class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)) {
    class TelescopeServiceProvider extends \Laravel\Telescope\TelescopeApplicationServiceProvider
    {
        /**
         * Register any application services.
         */
        public function register(): void
        {
            // Telescope::night();

            $this->hideSensitiveRequestDetails();

            $isLocal = $this->app->environment('local');

            \Laravel\Telescope\Telescope::filter(function (\Laravel\Telescope\IncomingEntry $entry) use ($isLocal) {
                return $isLocal ||
                       $entry->isReportableException() ||
                       $entry->isFailedRequest() ||
                       $entry->isFailedJob() ||
                       $entry->isScheduledTask() ||
                       $entry->hasMonitoredTag();
            });
        }

        /**
         * Prevent sensitive request details from being logged by Telescope.
         */
        protected function hideSensitiveRequestDetails(): void
        {
            if ($this->app->environment('local')) {
                return;
            }

            \Laravel\Telescope\Telescope::hideRequestParameters(['_token']);

            \Laravel\Telescope\Telescope::hideRequestHeaders([
                'cookie',
                'x-csrf-token',
                'x-xsrf-token',
            ]);
        }

        /**
         * Register the Telescope gate.
         */
        protected function gate(): void
        {
            \Illuminate\Support\Facades\Gate::define('viewTelescope', function ($user) {
                return in_array($user->email, [
                    //
                ]);
            });
        }
    }
} else {
    // Telescope не установлен (production/dev без --dev зависимостей)
    class TelescopeServiceProvider extends ServiceProvider
    {
        public function register(): void
        {
            //
        }
    }
}
