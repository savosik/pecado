<?php

namespace App\Http\Controllers\Admin;

use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class StoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Story::query()->withCount('slides');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'sort_order');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $stories = $query->paginate(15)->withQueryString();

        return Inertia::render('Admin/Pages/Stories/Index', [
            'stories' => $stories,
            'filters' => $request->only(['search', 'sort_by', 'sort_order']),
        ]);
    }

    public function search(Request $request)
    {
        $query = $request->input('q', '');
        
        $stories = Story::query()
            ->where('name', 'like', "%{$query}%")
            ->limit(10)
            ->get(['id', 'name']);

        return response()->json($stories);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Stories/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:stories,slug',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $story = Story::create($validated);

        return redirect()
            ->route('admin.stories.edit', $story)
            ->with('success', 'Сторис успешно создан. Теперь добавьте слайды.');
    }

    public function edit(Story $story)
    {
        $story->load(['slides' => function ($query) {
            $query->orderBy('sort_order');
        }]);

        // Добавить URL медиафайлов и linkable_name к каждому слайду
        $story->slides->transform(function ($slide) {
            $slide->media_url = $slide->getFirstMediaUrl('default');
            $slide->media_thumbnail = $slide->getFirstMedia('default')?->getUrl('thumb') ?? $slide->media_url;
            
            if ($slide->linkable) {
                $slide->linkable_name = $slide->linkable->title ?? $slide->linkable->name ?? null;
            } else {
                $slide->linkable_name = null;
            }
            
            return $slide;
        });

        return Inertia::render('Admin/Pages/Stories/Edit', [
            'story' => $story,
        ]);
    }

    public function update(Request $request, Story $story)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:stories,slug,' . $story->id,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $story->update($validated);

        return redirect()
            ->route('admin.stories.edit', $story)
            ->with('success', 'Сторис успешно обновлён');
    }

    public function destroy(Story $story)
    {
        $story->delete(); // Cascade удалит слайды

        return redirect()
            ->route('admin.stories.index')
            ->with('success', 'Сторис успешно удалён');
    }
}
