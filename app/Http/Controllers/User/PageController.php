<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PageController extends Controller
{
    /**
     * Показать CMS-страницу по slug.
     */
    public function show(Request $request, string $slug)
    {
        $page = Page::where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        // HTML-санитизация — оставляем только безопасные теги
        $allowedTags = '<p><br><h1><h2><h3><h4><h5><h6><ul><ol><li><a><strong><b><em><i><img><table><thead><tbody><tr><td><th><blockquote><hr><figure><figcaption><code><pre><span><div><section><sub><sup>';
        $sanitizedContent = strip_tags($page->content, $allowedTags);

        return Inertia::render('User/Pages/Show', [
            'page' => [
                'id'      => $page->id,
                'title'   => $page->title,
                'slug'    => $page->slug,
                'content' => $sanitizedContent,
            ],
            'seo' => [
                'title'       => $page->meta_title ?: $page->title,
                'description' => $page->meta_description ?: mb_substr(strip_tags($page->content), 0, 160),
                'url'         => $request->url(),
                'type'        => 'article',
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => $page->title],
            ],
        ]);
    }
}
