<?php

namespace App\Observers;

use App\Models\StorySlide;
use App\Support\HomeCache;

class StorySlideObserver
{
    public function saved(StorySlide $slide): void
    {
        HomeCache::flushStories();
    }

    public function deleted(StorySlide $slide): void
    {
        HomeCache::flushStories();
    }
}
