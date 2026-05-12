<?php

namespace App\Observers;

use App\Models\MenuItem;
use App\Support\HomeCache;

class MenuItemObserver
{
    public function saved(MenuItem $menuItem): void
    {
        HomeCache::flushMenus();
    }

    public function deleted(MenuItem $menuItem): void
    {
        HomeCache::flushMenus();
    }
}
