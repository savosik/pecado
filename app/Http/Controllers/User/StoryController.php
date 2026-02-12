<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Story;
use Illuminate\Support\Facades\Cache;

class StoryController extends Controller
{
    /**
     * Получить активные stories со слайдами для публичной части.
     */
    public function index()
    {
        $stories = self::getCachedStories();

        return response()->json($stories);
    }

    /**
     * Получить stories с кешированием (10 мин).
     */
    public static function getCachedStories(): array
    {
        return Cache::remember('user.stories.active', 600, function () {
            return Story::active()
                ->published()
                ->ordered()
                ->with(['slides' => function ($query) {
                    $query->orderBy('sort_order');
                }])
                ->get()
                ->map(function (Story $story) {
                    return [
                        'id'         => $story->id,
                        'name'       => $story->name,
                        'slug'       => $story->slug,
                        'show_name'  => $story->show_name,
                        'slides'     => $story->slides->map(function ($slide) {
                            $mediaUrl = $slide->getFirstMediaUrl('default');
                            $mediaMime = $slide->getFirstMedia('default')?->mime_type ?? '';

                            return [
                                'id'          => $slide->id,
                                'title'       => $slide->title,
                                'content'     => $slide->content,
                                'button_text' => $slide->button_text,
                                'button_url'  => $slide->button_url,
                                'media_url'   => $mediaUrl,
                                'media_type'  => str_starts_with($mediaMime, 'video/') ? 'video' : 'image',
                                'duration'    => $slide->duration ?? 5000,
                            ];
                        })->toArray(),
                    ];
                })
                ->filter(function ($story) {
                    return count($story['slides']) > 0;
                })
                ->values()
                ->toArray();
        });
    }
}
