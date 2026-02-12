<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FaqController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->input('q', '');

        $query = Faq::where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($q) {
            $query->where(function ($builder) use ($q) {
                $builder->where('title', 'LIKE', "%{$q}%")
                    ->orWhere('content', 'LIKE', "%{$q}%");
            });
        }

        $faqs = $query->get();

        return Inertia::render('User/Faq/Index', [
            'faqs' => $faqs,
            'q' => $q,
            'seo' => [
                'title' => 'FAQ — Часто задаваемые вопросы',
                'description' => 'Ответы на часто задаваемые вопросы о доставке, оплате, возвратах и других аспектах работы нашего магазина.',
                'url' => $request->url(),
                'type' => 'website',
                'structured_data' => $this->buildFaqStructuredData($faqs),
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'FAQ'],
            ],
        ]);
    }

    /**
     * Генерирует JSON-LD FAQPage structured data.
     */
    private function buildFaqStructuredData($faqs): array
    {
        if ($faqs->isEmpty()) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $faqs->map(fn ($faq) => [
                '@type' => 'Question',
                'name' => $faq->title,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => strip_tags($faq->content),
                ],
            ])->values()->all(),
        ];
    }
}
