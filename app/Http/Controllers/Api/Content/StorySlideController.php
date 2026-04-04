<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\Content\Traits\HandlesMediaUpload;
use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\StorySlide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD слайдов сториз (вложенный ресурс в stories).
 *
 * @tags Слайды сториз
 */
class StorySlideController extends Controller
{
    use HandlesMediaUpload;

    /**
     * Список слайдов сториса.
     *
     * Возвращает все слайды указанного сториса по порядку sort_order.
     */
    public function index(Story $story): JsonResponse
    {
        $slides = $story->slides()->orderBy('sort_order')->get();

        return response()->json([
            'data' => $slides->map(fn ($slide) => $this->format($slide)),
        ]);
    }

    /**
     * Добавить слайд в сторис.
     *
     * Поддерживает загрузку изображения/видео файлом (media) или URL (media_url).
     * Кнопка с ссылкой, длительность показа (1–60 сек), полиморфная ссылка на сущность.
     */
    public function store(Request $request, Story $story): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'button_text' => 'nullable|string|max:100',
            'button_url' => 'nullable|string',
            'linkable_type' => 'nullable|string',
            'linkable_id' => 'nullable|integer',
            'duration' => 'integer|min:1|max:60',
            'sort_order' => 'integer',
            'media' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:20480',
            'media_url' => 'nullable|url',
        ]);

        $validated['story_id'] = $story->id;

        $slide = StorySlide::create($validated);

        $this->handleMediaUpload($request, $slide, 'media', 'default');

        return response()->json(['data' => $this->format($slide->fresh())], 201);
    }

    /**
     * Обновить слайд.
     *
     * Можно передавать только изменённые поля. Новое медиа заменяет старое.
     */
    public function update(Request $request, Story $story, StorySlide $slide): JsonResponse
    {
        if ($slide->story_id !== $story->id) {
            abort(404, 'Слайд не принадлежит этому сторису');
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'button_text' => 'nullable|string|max:100',
            'button_url' => 'nullable|string',
            'linkable_type' => 'nullable|string',
            'linkable_id' => 'nullable|integer',
            'duration' => 'integer|min:1|max:60',
            'sort_order' => 'integer',
            'media' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:20480',
            'media_url' => 'nullable|url',
        ]);

        $slide->update($validated);

        $this->handleMediaUpload($request, $slide, 'media', 'default', clearFirst: true);

        return response()->json(['data' => $this->format($slide->fresh())]);
    }

    /**
     * Удалить слайд.
     */
    public function destroy(Story $story, StorySlide $slide): JsonResponse
    {
        if ($slide->story_id !== $story->id) {
            abort(404, 'Слайд не принадлежит этому сторису');
        }

        $slide->delete();

        return response()->json(null, 204);
    }

    /**
     * Изменить порядок слайдов.
     *
     * Передайте массив slides с id и новым sort_order для каждого слайда.
     */
    public function reorder(Request $request, Story $story): JsonResponse
    {
        $validated = $request->validate([
            'slides' => 'required|array',
            'slides.*.id' => 'required|exists:story_slides,id',
            'slides.*.sort_order' => 'required|integer',
        ]);

        foreach ($validated['slides'] as $slideData) {
            StorySlide::where('id', $slideData['id'])
                ->where('story_id', $story->id)
                ->update(['sort_order' => $slideData['sort_order']]);
        }

        return response()->json([
            'message' => 'Порядок слайдов обновлён',
        ]);
    }

    private function format(StorySlide $slide): array
    {
        return [
            'id' => $slide->id,
            'story_id' => $slide->story_id,
            'title' => $slide->title,
            'content' => $slide->content,
            'button_text' => $slide->button_text,
            'button_url' => $slide->button_url,
            'linkable_type' => $slide->linkable_type,
            'linkable_id' => $slide->linkable_id,
            'duration' => $slide->duration,
            'sort_order' => $slide->sort_order,
            'media_url' => $slide->getFirstMediaUrl('default') ?: null,
            'created_at' => $slide->created_at?->toIso8601String(),
            'updated_at' => $slide->updated_at?->toIso8601String(),
        ];
    }
}
