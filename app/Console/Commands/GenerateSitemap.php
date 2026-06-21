<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Brand;
use App\Models\BrandStory;
use App\Models\Category;
use App\Models\News;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Console\Command;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate
        {--path= : Путь к файлу sitemap (по умолчанию public/sitemap.xml)}';

    protected $description = 'Генерация sitemap.xml';

    public function handle(): int
    {
        $sitemap = Sitemap::create();

        $this->addStaticPages($sitemap);
        $this->addCategories($sitemap);
        $this->addBrands($sitemap);
        $this->addProducts($sitemap);
        $this->addNews($sitemap);
        $this->addArticles($sitemap);
        $this->addPromotions($sitemap);
        $this->addBrandStories($sitemap);

        $path = $this->option('path') ?: public_path('sitemap.xml');
        $sitemap->writeToFile($path);

        $this->info('sitemap.xml сгенерирован: '.count($sitemap->getTags()).' URL');

        return self::SUCCESS;
    }

    private function addStaticPages(Sitemap $sitemap): void
    {
        $sitemap->add(Url::create(route('home'))->setPriority(1.0)->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY));
        $sitemap->add(Url::create(route('products.index'))->setPriority(0.9)->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY));
        $sitemap->add(Url::create(route('news.index'))->setPriority(0.7)->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY));
        $sitemap->add(Url::create(route('articles.index'))->setPriority(0.7)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY));
        $sitemap->add(Url::create(route('promotions.index'))->setPriority(0.7)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY));
        $sitemap->add(Url::create(route('brand-stories.index'))->setPriority(0.6)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY));
        $sitemap->add(Url::create(route('faq'))->setPriority(0.5)->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY));
    }

    private function addCategories(Sitemap $sitemap): void
    {
        Category::query()
            ->where('is_active', true)
            ->whereNotNull('slug')
            ->select(['slug', 'updated_at'])
            ->each(function (Category $category) use ($sitemap) {
                $sitemap->add(
                    Url::create(route('products.category', $category->slug))
                        ->setLastModificationDate($category->updated_at)
                        ->setPriority(0.8)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                );
            });
    }

    private function addBrands(Sitemap $sitemap): void
    {
        Brand::query()
            ->whereNotNull('slug')
            ->select(['slug', 'updated_at'])
            ->each(function (Brand $brand) use ($sitemap) {
                $sitemap->add(
                    Url::create(route('products.brand', $brand->slug))
                        ->setLastModificationDate($brand->updated_at)
                        ->setPriority(0.7)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                );
            });
    }

    private function addProducts(Sitemap $sitemap): void
    {
        Product::query()
            ->where('hidden', false)
            ->whereNotNull('slug')
            ->select(['slug', 'updated_at'])
            ->each(function (Product $product) use ($sitemap) {
                $sitemap->add(
                    Url::create(route('products.show', $product->slug))
                        ->setLastModificationDate($product->updated_at)
                        ->setPriority(0.9)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                );
            });
    }

    private function addNews(Sitemap $sitemap): void
    {
        News::published()
            ->select(['slug', 'updated_at'])
            ->each(function (News $news) use ($sitemap) {
                $sitemap->add(
                    Url::create(route('news.show', $news->slug))
                        ->setLastModificationDate($news->updated_at)
                        ->setPriority(0.6)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                );
            });
    }

    private function addArticles(Sitemap $sitemap): void
    {
        Article::published()
            ->select(['slug', 'updated_at'])
            ->each(function (Article $article) use ($sitemap) {
                $sitemap->add(
                    Url::create(route('articles.show', $article->slug))
                        ->setLastModificationDate($article->updated_at)
                        ->setPriority(0.6)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                );
            });
    }

    private function addPromotions(Sitemap $sitemap): void
    {
        Promotion::query()
            ->whereNotNull('slug')
            ->select(['slug', 'updated_at'])
            ->each(function (Promotion $promotion) use ($sitemap) {
                $sitemap->add(
                    Url::create(route('promotions.show', $promotion->slug))
                        ->setLastModificationDate($promotion->updated_at)
                        ->setPriority(0.6)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                );
            });
    }

    private function addBrandStories(Sitemap $sitemap): void
    {
        BrandStory::published()
            ->select(['slug', 'updated_at'])
            ->each(function (BrandStory $story) use ($sitemap) {
                $sitemap->add(
                    Url::create(route('brand-stories.show', $story->slug))
                        ->setLastModificationDate($story->updated_at)
                        ->setPriority(0.5)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                );
            });
    }
}
