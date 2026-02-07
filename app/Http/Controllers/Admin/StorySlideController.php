<?php

namespace App\Http\Controllers\Admin;

use App\Models\Story;
use App\Models\StorySlide;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StorySlideController extends Controller
{
    public function store(Request $request, Story $story)
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
            'media' => 'required|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:20480',
        ]);

        $validated['story_id'] = $story->id;

        $slide = StorySlide::create($validated);

        if ($request->hasFile('media')) {
            $slide->addMediaFromRequest('media')->toMediaCollection('default');
        }

        // Загрузить созданный слайд с медиа
        $slide->load('media');
        $slide->media_url = $slide->getFirstMediaUrl('default');
        $slide->media_thumbnail = $slide->getFirstMedia('default')?->getUrl('thumb') ?? $slide->media_url;

        return response()->json([
            'message' => 'Слайд успешно создан',
            'slide' => $slide,
        ]);
    }

    public function update(Request $request, Story $story, StorySlide $slide)
    {
        // Проверить что слайд принадлежит этому сторису
        if ($slide->story_id !== $story->id) {
            abort(403, 'Слайд не принадлежит этому сторису');
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
        ]);

        $slide->update($validated);

        if ($request->hasFile('media')) {
            $slide->clearMediaCollection('default');
            $slide->addMediaFromRequest('media')->toMediaCollection('default');
        }

        // Загрузить обновлённый слайд с медиа
        $slide->load('media');
        $slide->media_url = $slide->getFirstMediaUrl('default');
        $slide->media_thumbnail = $slide->getFirstMedia('default')?->getUrl('thumb') ?? $slide->media_url;

        if ($slide->linkable) {
            $slide->linkable_name = $slide->linkable->title ?? $slide->linkable->name ?? null;
        } else {
            $slide->linkable_name = null;
        }

        return response()->json([
            'message' => 'Слайд успешно обновлён',
            'slide' => $slide,
        ]);
    }

    public function destroy(Story $story, StorySlide $slide)
    {
        // Проверить что слайд принадлежит этому сторису
        if ($slide->story_id !== $story->id) {
            abort(403, 'Слайд не принадлежит этому сторису');
        }

        $slide->delete();

        return response()->json([
            'message' => 'Слайд успешно удалён',
        ]);
    }

    public function reorder(Request $request, Story $story)
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
}
