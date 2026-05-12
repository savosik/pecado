<?php

namespace App\Console\Commands;

use App\Support\HomeCache;
use Illuminate\Console\Command;

class FlushHomeCache extends Command
{
    protected $signature = 'cache:flush-home';

    protected $description = 'Сбросить все кеши публички (баннеры, сторис, подборки, меню, футер-категории).';

    public function handle(): int
    {
        HomeCache::flushAll();

        $this->info('Кеши главной страницы и шапки/подвала сброшены.');

        return self::SUCCESS;
    }
}
