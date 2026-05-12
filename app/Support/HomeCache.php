<?php

namespace App\Support;

use App\Http\Controllers\User\ProductSelectionController;
use App\Models\Region;
use Illuminate\Support\Facades\Cache;

/**
 * Централизованная инвалидация кешей публички (главная, шапка, подвал).
 *
 * Кеши заполняются в:
 *  - App\Http\Controllers\User\BannerController::getCachedBanners
 *  - App\Http\Controllers\User\StoryController::getCachedStories
 *  - App\Http\Controllers\User\ProductSelectionController::getCached*
 *  - App\Http\Middleware\HandleInertiaRequests (footer.categories, menu.header, menu.footer)
 *
 * Используется наблюдателями (App\Observers\*) и при необходимости — напрямую.
 */
class HomeCache
{
    public static function flushBanners(): void
    {
        Cache::forget('user.banners.active.all');

        foreach (self::regionIds() as $regionId) {
            Cache::forget("user.banners.active.{$regionId}");
        }
    }

    public static function flushStories(): void
    {
        Cache::forget('user.stories.active.all');

        foreach (self::regionIds() as $regionId) {
            Cache::forget("user.stories.active.{$regionId}");
        }
    }

    public static function flushSelections(): void
    {
        Cache::forget(ProductSelectionController::CACHE_KEY_SELECTIONS.'.all');

        foreach (self::regionIds() as $regionId) {
            Cache::forget(ProductSelectionController::CACHE_KEY_SELECTIONS.".{$regionId}");
        }
    }

    public static function flushNewProducts(): void
    {
        foreach (self::productLimits() as $limit) {
            Cache::forget(ProductSelectionController::CACHE_KEY_NEW.".{$limit}");
        }
    }

    public static function flushBestsellers(): void
    {
        foreach (self::productLimits() as $limit) {
            Cache::forget(ProductSelectionController::CACHE_KEY_BESTSELLERS.".{$limit}");
        }
    }

    public static function flushFooterCategories(): void
    {
        Cache::forget('footer.categories');
    }

    public static function flushMenus(): void
    {
        Cache::forget('menu.header');
        Cache::forget('menu.footer');
    }

    /**
     * Полный сброс всех кешей публички — для редких сценариев
     * (массовый импорт, очистка через консольную команду).
     */
    public static function flushAll(): void
    {
        self::flushBanners();
        self::flushStories();
        self::flushSelections();
        self::flushNewProducts();
        self::flushBestsellers();
        self::flushFooterCategories();
        self::flushMenus();
    }

    /**
     * Сбросить кеши, в которые попадают данные товара
     * (подборки/новинки/бестселлеры на главной).
     */
    public static function flushProductRelated(): void
    {
        self::flushSelections();
        self::flushNewProducts();
        self::flushBestsellers();
    }

    /**
     * @return array<int, int>
     */
    private static function regionIds(): array
    {
        if (! self::tableExists('regions')) {
            return [];
        }

        return Region::query()->pluck('id')->all();
    }

    /**
     * Известные лимиты, под которые формируются ключи кеша новинок/бестселлеров.
     * Сейчас публичный фронт использует только 10 — но если появятся другие, добавить сюда.
     *
     * @return array<int, int>
     */
    private static function productLimits(): array
    {
        return [10];
    }

    private static function tableExists(string $table): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
