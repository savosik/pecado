<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class NewsController extends Controller
{
    /**
     * Список новостей с пагинацией (12 на страницу).
     */
    public function index(Request $request)
    {
        $news = News::published()
            ->with('tags')
            ->orderByDesc('published_at')
            ->paginate(12)
            ->withQueryString();

        // Подгружаем медиа для каждого элемента
        $news->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'title' => $item->title,
                'slug' => $item->slug,
                'excerpt' => Str::limit(strip_tags($item->detailed_description), 160),
                'image' => $item->getFirstMediaUrl('list-item') ?: null,
                'published_at' => $item->published_at?->toISOString(),
                'tags' => $item->tags->pluck('name')->toArray(),
            ];
        });

        return Inertia::render('User/News/Index', [
            'news' => $news,
            'seo' => [
                'title' => 'Новости',
                'description' => 'Последние новости и обновления нашего магазина.',
                'url' => $request->url(),
                'type' => 'website',
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'Новости'],
            ],
        ]);
    }

    /**
     * Детальная страница новости.
     */
    public function show(Request $request, string $slug)
    {
        $newsItem = News::published()
            ->with('tags')
            ->where('slug', $slug)
            ->firstOrFail();

        // HTML-санитизация через HTMLPurifier
        $sanitizedContent = clean($newsItem->detailed_description);

        $descriptionText = $newsItem->meta_description
            ?: Str::limit(strip_tags($newsItem->detailed_description), 160);

        // Structured data (Article)
        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $newsItem->title,
            'datePublished' => $newsItem->published_at?->toIso8601String(),
            'dateModified' => $newsItem->updated_at?->toIso8601String(),
            'description' => $descriptionText,
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
            ],
        ];

        $detailImage = $newsItem->getFirstMediaUrl('detail-item-desktop');
        if ($detailImage) {
            $structuredData['image'] = $detailImage;
        }

        return Inertia::render('User/News/Show', [
            'newsItem' => [
                'id' => $newsItem->id,
                'title' => $newsItem->title,
                'slug' => $newsItem->slug,
                'content' => $sanitizedContent,
                'image' => $detailImage ?: null,
                'published_at' => $newsItem->published_at?->toISOString(),
                'tags' => $newsItem->tags->pluck('name')->toArray(),
            ],
            'seo' => [
                'title' => $newsItem->meta_title ?: $newsItem->title,
                'description' => $descriptionText,
                'url' => $request->url(),
                'type' => 'article',
                'image' => $detailImage ?: null,
                'structured_data' => $structuredData,
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'Новости', 'url' => '/news'],
                ['label' => $newsItem->title],
            ],
        ]);
    }
}
