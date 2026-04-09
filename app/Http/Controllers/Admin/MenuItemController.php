<?php

namespace App\Http\Controllers\Admin;

use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class MenuItemController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = MenuItem::query();

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%");
            });
        }

        // Фильтр по расположению
        if ($location = $request->input('location')) {
            $query->where('location', $location);
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'sort_order');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 25);
        $menuItems = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/MenuItems/Index', [
            'menuItems' => $menuItems,
            'filters' => $request->only(['search', 'sort_by', 'sort_order', 'per_page', 'location']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/MenuItems/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'          => 'required|string|max:255',
            'url'            => 'required|string|max:500',
            'icon'           => 'nullable|string|max:100',
            'badge_text'     => 'nullable|string|max:50',
            'badge_color'    => 'nullable|string|max:20',
            'location'       => 'required|in:header,footer,both',
            'footer_group'   => 'nullable|string|in:company,buyers',
            'sort_order'     => 'nullable|integer|min:0',
            'is_published'   => 'boolean',
            'open_in_new_tab'=> 'boolean',
        ]);

        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_published'] = $validated['is_published'] ?? true;
        $validated['open_in_new_tab'] = $validated['open_in_new_tab'] ?? false;

        $menuItem = MenuItem::create($validated);

        $this->clearMenuCache();

        return $this->redirectAfterSave($request, 'admin.menu-items.index', 'admin.menu-items.edit', $menuItem, 'Пункт меню успешно создан');
    }

    public function edit(MenuItem $menuItem)
    {
        return Inertia::render('Admin/Pages/MenuItems/Edit', [
            'menuItem' => $menuItem,
        ]);
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $validated = $request->validate([
            'title'          => 'required|string|max:255',
            'url'            => 'required|string|max:500',
            'icon'           => 'nullable|string|max:100',
            'badge_text'     => 'nullable|string|max:50',
            'badge_color'    => 'nullable|string|max:20',
            'location'       => 'required|in:header,footer,both',
            'footer_group'   => 'nullable|string|in:company,buyers',
            'sort_order'     => 'nullable|integer|min:0',
            'is_published'   => 'boolean',
            'open_in_new_tab'=> 'boolean',
        ]);

        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $menuItem->update($validated);

        $this->clearMenuCache();

        return $this->redirectAfterSave($request, 'admin.menu-items.index', 'admin.menu-items.edit', $menuItem, 'Пункт меню успешно обновлён');
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();

        $this->clearMenuCache();

        return redirect()->route('admin.menu-items.index')->with('success', 'Пункт меню успешно удалён');
    }

    /**
     * Очистка кеша меню после изменений.
     */
    protected function clearMenuCache(): void
    {
        Cache::forget('menu.header');
        Cache::forget('menu.footer');
    }
}
