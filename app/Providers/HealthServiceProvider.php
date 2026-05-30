<?php

namespace App\Providers;

use App\Checks\RabbitMQCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\MeilisearchCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Health::checks([
            DatabaseCheck::new()->connectionName('mysql')->name('Database: Main'),
            DatabaseCheck::new()->connectionName('prices')->name('Database: Prices'),
            RedisCheck::new(),
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(75)
                ->failWhenUsedSpaceIsAbovePercentage(85),
            HorizonCheck::new(),
            RabbitMQCheck::new()->name('RabbitMQ'),
            MeilisearchCheck::new()
                ->name('Meilisearch')
                ->url(rtrim((string) config('scout.meilisearch.host', 'http://meilisearch:7700'), '/').'/health')
                ->token((string) config('scout.meilisearch.key', '')),
        ]);
    }
}
