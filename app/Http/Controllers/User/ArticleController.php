<?php

namespace App\Http\Controllers\User;

use App\Helpers\ContentHelper;
use App\Helpers\TagHelper;
use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ArticleController extends Controller
{
    /**
     * Список статей с пагинацией (12 на страницу).
     */
    public function index(Request $request)
    {
        $selectedTags = $request->input('tags', []);

        $query = Article::published()->with('tags')->orderByDesc('published_at')
            ->forRegion(Auth::user()?->region_id);

        if (! empty($selectedTags)) {
            $query->withAnyTags($selectedTags);
        }

        $articles = $query->paginate(12)->withQueryString();

        $availableTags = \Spatie\Tags\Tag::query()
            ->join('taggables', 'tags.id', '=', 'taggables.tag_id')
            ->where('taggables.taggable_type', \App\Models\Article::class)
            ->whereIn('taggables.taggable_id', Article::published()->select('id'))
            ->distinct()
            ->orderBy('tags.name')
            ->pluck('tags.name')
            ->toArray();

        // Подгружаем медиа для каждого элемента
        $articles->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'title' => $item->title,
                'slug' => $item->slug,
                'excerpt' => $item->short_description ?: ContentHelper::extractText($item->detailed_description, 160),
                'image' => $item->getFirstMediaUrl('list-item') ?: null,
                'published_at' => $item->published_at?->toISOString(),
                'tags' => TagHelper::names($item->tags),
            ];
        });

        return Inertia::render('User/Articles/Index', [
            'articles' => $articles,
            'availableTags' => $availableTags,
            'selectedTags' => $selectedTags,
            'seo' => [
                'title' => 'Статьи',
                'description' => 'Полезные статьи и материалы нашего магазина.',
                'url' => $request->url(),
                'type' => 'website',
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'Статьи'],
            ],
        ]);
    }

    /**
     * Детальная страница статьи.
     */
    public function show(Request $request, string $slug)
    {
        $article = Article::published()
            ->with('tags')
            ->where('slug', $slug)
            ->forRegion(Auth::user()?->region_id)
            ->firstOrFail();

        // Выводим контент как есть, так как он создаётся админами и содержит сложную журнальную HTML-верстку
        $sanitizedContent = $article->detailed_description;

        $descriptionText = $article->meta_description
            ?: ($article->short_description ?: ContentHelper::extractText($article->detailed_description, 160));

        // Structured data (Article)
        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $article->title,
            'datePublished' => $article->published_at?->toIso8601String(),
            'dateModified' => $article->updated_at?->toIso8601String(),
            'description' => $descriptionText,
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
            ],
        ];

        $detailImage = $article->getFirstMediaUrl('detail-item-desktop');
        if ($detailImage) {
            $structuredData['image'] = $detailImage;
        }

        return Inertia::render('User/Articles/Show', [
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'content' => $sanitizedContent,
                'image' => $detailImage ?: null,
                'published_at' => $article->published_at?->toISOString(),
                'tags' => TagHelper::names($article->tags),
            ],
            'seo' => [
                'title' => $article->meta_title ?: $article->title,
                'description' => $descriptionText,
                'url' => $request->url(),
                'type' => 'article',
                'image' => $detailImage ?: null,
                'structured_data' => $structuredData,
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'Статьи', 'url' => '/articles'],
                ['label' => $article->title],
            ],
        ]);
    }
}
