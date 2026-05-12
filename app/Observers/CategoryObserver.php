<?php

namespace App\Observers;

use App\Models\Category;
use App\Support\HomeCache;

class CategoryObserver
{
    public function saved(Category $category): void
    {
        HomeCache::flushFooterCategories();
    }

    public function deleted(Category $category): void
    {
        HomeCache::flushFooterCategories();
    }
}
