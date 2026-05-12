<?php

namespace App\Observers;

use App\Models\Story;
use App\Support\HomeCache;

class StoryObserver
{
    public function saved(Story $story): void
    {
        HomeCache::flushStories();
    }

    public function deleted(Story $story): void
    {
        HomeCache::flushStories();
    }
}
