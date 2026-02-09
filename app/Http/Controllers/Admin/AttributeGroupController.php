<?php

namespace App\Http\Controllers\Admin;

use App\Models\AttributeGroup;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class AttributeGroupController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = AttributeGroup::query()->withCount('attributes');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'name', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $attributeGroups = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/AttributeGroups/Index', [
            'attributeGroups' => $attributeGroups,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/AttributeGroups/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $attributeGroup = AttributeGroup::create($validated);

        return $this->redirectAfterSave($request, 'admin.attribute-groups.index', 'admin.attribute-groups.edit', $attributeGroup, 'Группа атрибутов успешно создана');
    }

    public function edit(AttributeGroup $attributeGroup)
    {
        return Inertia::render('Admin/Pages/AttributeGroups/Edit', [
            'attributeGroup' => $attributeGroup,
        ]);
    }

    public function update(Request $request, AttributeGroup $attributeGroup)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $attributeGroup->update($validated);

        return $this->redirectAfterSave($request, 'admin.attribute-groups.index', 'admin.attribute-groups.edit', $attributeGroup, 'Группа атрибутов успешно обновлена');
    }

    public function destroy(AttributeGroup $attributeGroup)
    {
        $attributeGroup->delete();

        return redirect()->route('admin.attribute-groups.index')->with('success', 'Группа атрибутов успешно удалена');
    }

    public function search(Request $request)
    {
        $query = AttributeGroup::query();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $groups = $query->select('id', 'name')
            ->limit(20)
            ->get()
            ->map(function ($group) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                ];
            });

        return response()->json($groups);
    }
}
