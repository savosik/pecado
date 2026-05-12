<?php

namespace App\Observers;

use App\Models\ProductSelection;
use App\Support\HomeCache;

class ProductSelectionObserver
{
    public function saved(ProductSelection $selection): void
    {
        HomeCache::flushSelections();
    }

    public function deleted(ProductSelection $selection): void
    {
        HomeCache::flushSelections();
    }
}
