<?php

namespace App\Http\Controllers\Admin;

use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class FaqController extends Controller
{
    public function index(Request $request)
    {
        $query = Faq::query();

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $faqs = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Faqs/Index', [
            'faqs' => $faqs,
            'filters' => $request->only(['search', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Faqs/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        Faq::create($validated);

        return redirect()
            ->route('admin.faqs.index')
            ->with('success', 'FAQ успешно создан');
    }

    public function edit(Faq $faq)
    {
        return Inertia::render('Admin/Pages/Faqs/Edit', [
            'faq' => $faq,
        ]);
    }

    public function update(Request $request, Faq $faq)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $faq->update($validated);

        return redirect()
            ->route('admin.faqs.index')
            ->with('success', 'FAQ успешно обновлён');
    }

    public function destroy(Faq $faq)
    {
        $faq->delete();

        return redirect()
            ->route('admin.faqs.index')
            ->with('success', 'FAQ успешно удалён');
    }

    public function search(Request $request)
    {
        $query = Faq::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%");
            });
        }

        $faqs = $query->select('id', 'title')
            ->limit(20)
            ->get()
            ->map(function ($faq) {
                return [
                    'id' => $faq->id,
                    'name' => $faq->title,
                ];
            });

        return response()->json($faqs);
    }
}
