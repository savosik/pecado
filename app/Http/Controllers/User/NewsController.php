<?php

namespace App\Http\Controllers\User;

use App\Helpers\ContentHelper;
use App\Helpers\TagHelper;
use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class NewsController extends Controller
{
    /**
     * Список новостей с пагинацией (12 на страницу).
     */
    public function index(Request $request)
    {
        $selectedTags = $request->input('tags', []);

        $query = News::published()->with('tags')->orderByDesc('published_at')
            ->forRegion(Auth::user()?->region_id);

        if (! empty($selectedTags)) {
            $query->withAnyTags($selectedTags);
        }

        $news = $query->paginate(12)->withQueryString();

        // Все теги раздела (из опубликованных записей)
        $availableTags = TagHelper::names(
            \Spatie\Tags\Tag::query()
                ->join('taggables', 'tags.id', '=', 'taggables.tag_id')
                ->where('taggables.taggable_type', \App\Models\News::class)
                ->whereIn('taggables.taggable_id', News::published()->select('id'))
                ->distinct()
                ->select('tags.*')
                ->get()
        );
        sort($availableTags);

        // Подгружаем медиа для каждого элемента
        $news->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'title' => $item->title,
                'slug' => $item->slug,
                'excerpt' => $item->short_description
                    ?: ContentHelper::extractText($item->detailed_description, 160),
                'image' => $item->getFirstMediaUrl('list-item') ?: null,
                'published_at' => $item->published_at?->toISOString(),
                'tags' => TagHelper::names($item->tags),
            ];
        });

        return Inertia::render('User/News/Index', [
            'news' => $news,
            'availableTags' => $availableTags,
            'selectedTags' => $selectedTags,
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
            ->forRegion(Auth::user()?->region_id)
            ->firstOrFail();

        // JSON-контент передаём как есть, HTML — санитизируем
        $content = $newsItem->detailed_description;
        $isJson = is_string($content) && str_starts_with(trim($content), '{');
        $sanitizedContent = $isJson ? $content : clean($content);

        $descriptionText = $newsItem->meta_description
            ?: $newsItem->short_description
            ?: ContentHelper::extractText($newsItem->detailed_description, 160);

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
                'tags' => TagHelper::names($newsItem->tags),
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
