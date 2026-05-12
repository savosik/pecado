<?php

namespace App\Observers;

use App\Models\Banner;
use App\Support\HomeCache;

class BannerObserver
{
    public function saved(Banner $banner): void
    {
        HomeCache::flushBanners();
    }

    public function deleted(Banner $banner): void
    {
        HomeCache::flushBanners();
    }
}
