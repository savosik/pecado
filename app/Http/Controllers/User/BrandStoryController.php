<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\BrandStory;
use App\Helpers\ContentHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BrandStoryController extends Controller
{
    /**
     * Список статей о брендах с пагинацией (12 на страницу).
     */
    public function index(Request $request)
    {
        $selectedTags = $request->input('tags', []);

        $query = BrandStory::published()->with(['tags', 'brand'])->orderByDesc('published_at')
            ->forRegion(Auth::user()?->region_id);

        if (!empty($selectedTags)) {
            $query->withAnyTags($selectedTags);
        }

        $brandStories = $query->paginate(12)->withQueryString();

        $availableTags = \Spatie\Tags\Tag::query()
            ->join('taggables', 'tags.id', '=', 'taggables.tag_id')
            ->where('taggables.taggable_type', \App\Models\BrandStory::class)
            ->whereIn('taggables.taggable_id', BrandStory::published()->select('id'))
            ->distinct()
            ->orderBy('tags.name')
            ->pluck('tags.name')
            ->toArray();

        // Подгружаем медиа для каждого элемента
        $brandStories->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'title' => $item->title,
                'slug' => $item->slug,
                'excerpt' => $item->short_description ?: ContentHelper::extractText($item->detailed_description, 160),
                'image' => $item->getFirstMediaUrl('list-item') ?: null,
                'published_at' => $item->published_at?->toISOString(),
                'tags' => $item->tags->pluck('name')->toArray(),
                'brand' => $item->brand ? [
                    'id' => $item->brand->id,
                    'name' => $item->brand->name,
                    'slug' => $item->brand->slug,
                ] : null,
            ];
        });

        return Inertia::render('User/BrandStories/Index', [
            'brandStories' => $brandStories,
            'availableTags' => $availableTags,
            'selectedTags' => $selectedTags,
            'seo' => [
                'title' => 'О брендах',
                'description' => 'Статьи и описания брендов нашего магазина.',
                'url' => $request->url(),
                'type' => 'website',
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'О брендах'],
            ],
        ]);
    }

    /**
     * Детальная страница статьи о бренде.
     */
    public function show(Request $request, string $slug)
    {
        $brandStory = BrandStory::published()
            ->with(['tags', 'brand'])
            ->where('slug', $slug)
            ->forRegion(Auth::user()?->region_id)
            ->firstOrFail();

        // JSON-контент передаём как есть, HTML — санитизируем
        $content = $brandStory->detailed_description;
        $isJson = is_string($content) && str_starts_with(trim($content), '{');
        $sanitizedContent = $isJson ? $content : clean($content);

        $descriptionText = $brandStory->meta_description
            ?: ($brandStory->short_description ?: ContentHelper::extractText($brandStory->detailed_description, 160));

        // Structured data (Article)
        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $brandStory->title,
            'datePublished' => $brandStory->published_at?->toIso8601String(),
            'dateModified' => $brandStory->updated_at?->toIso8601String(),
            'description' => $descriptionText,
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
            ],
        ];

        $detailImage = $brandStory->getFirstMediaUrl('detail-item-desktop');
        if ($detailImage) {
            $structuredData['image'] = $detailImage;
        }

        return Inertia::render('User/BrandStories/Show', [
            'brandStory' => [
                'id' => $brandStory->id,
                'title' => $brandStory->title,
                'slug' => $brandStory->slug,
                'content' => $sanitizedContent,
                'image' => $detailImage ?: null,
                'published_at' => $brandStory->published_at?->toISOString(),
                'tags' => $brandStory->tags->pluck('name')->toArray(),
                'brand' => $brandStory->brand ? [
                    'id' => $brandStory->brand->id,
                    'name' => $brandStory->brand->name,
                    'slug' => $brandStory->brand->slug,
                ] : null,
            ],
            'seo' => [
                'title' => $brandStory->meta_title ?: $brandStory->title,
                'description' => $descriptionText,
                'url' => $request->url(),
                'type' => 'article',
                'image' => $detailImage ?: null,
                'structured_data' => $structuredData,
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'О брендах', 'url' => '/brand-stories'],
                ['label' => $brandStory->title],
            ],
        ]);
    }
}
