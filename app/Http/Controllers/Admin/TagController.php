<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spatie\Tags\Tag;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class TagController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = Tag::query();

        // Поиск
        if ($search = $request->input('search')) {
            $query->where('name->ru', 'like', "%{$search}%")
                ->orWhere('name->en', 'like', "%{$search}%")
                ->orWhere('slug->ru', 'like', "%{$search}%")
                ->orWhere('slug->en', 'like', "%{$search}%");
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'order_column');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $tags = $query->paginate($perPage)->withQueryString();

        // Преобразуем name для отображения
        $tags->getCollection()->transform(function ($tag) {
            $tag->display_name = is_array($tag->name) 
                ? ($tag->name['ru'] ?? $tag->name['en'] ?? implode(', ', $tag->name))
                : $tag->name;
            return $tag;
        });

        return Inertia::render('Admin/Pages/Tags/Index', [
            'tags' => $tags,
            'filters' => $request->only(['search', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Tags/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:255',
            'order_column' => 'nullable|integer',
        ]);

        // Spatie Tags автоматически создаст slug
        $tag = Tag::findOrCreate($validated['name'], $validated['type'] ?? null);

        if (isset($validated['order_column'])) {
            $tag->order_column = $validated['order_column'];
            $tag->save();
        }

        return $this->redirectAfterSave($request, 'admin.tags.index', 'admin.tags.edit', $tag, 'Тег успешно создан');
    }

    public function edit(Tag $tag)
    {
        // Преобразуем name для редактирования
        $tag->display_name = is_array($tag->name) 
            ? ($tag->name['ru'] ?? $tag->name['en'] ?? implode(', ', $tag->name))
            : $tag->name;

        return Inertia::render('Admin/Pages/Tags/Edit', [
            'tag' => $tag,
        ]);
    }

    public function update(Request $request, Tag $tag)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:255',
            'order_column' => 'nullable|integer',
        ]);

        // Обновляем name (Spatie Tags обновит slug автоматически)
        $tag->setTranslation('name', 'ru', $validated['name']);
        $tag->type = $validated['type'] ?? null;
        $tag->order_column = $validated['order_column'] ?? 0;
        $tag->save();

        return $this->redirectAfterSave($request, 'admin.tags.index', 'admin.tags.edit', $tag, 'Тег успешно обновлён');
    }

    public function destroy(Tag $tag)
    {
        $tag->delete();

        return redirect()->route('admin.tags.index')->with('success', 'Тег успешно удалён');
    }

    public function search(Request $request)
    {
        $query = $request->input('query');

        $tags = Tag::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%");
            })
            ->take(10)
            ->get()
            ->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ];
            });

        return response()->json($tags);
    }
}
