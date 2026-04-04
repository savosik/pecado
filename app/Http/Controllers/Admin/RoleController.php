<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    use RedirectsAfterSave;

    /**
     * Группировка ресурсов для UI матрицы прав.
     */
    protected array $permissionGroups = [
        'Каталог' => ['products', 'categories', 'brands', 'product-models', 'attributes', 'attribute-groups', 'size-charts', 'product-barcodes', 'certificates', 'product-exports'],
        'Склады' => ['warehouses', 'regions'],
        'Продажи' => ['orders', 'carts', 'returns', 'shipments', 'favorites', 'wishlist'],
        'Маркетинг' => ['promotions', 'product-selections'],
        'Пользователи' => ['users', 'user-questionnaires', 'companies', 'company-bank-accounts', 'delivery-addresses'],
        'Финансы' => ['currencies', 'contractor-balances', 'individual-prices'],
        'Контент' => ['articles', 'brand-stories', 'news', 'faqs', 'banners', 'pages', 'stories'],
        'Теги' => ['tags'],
        'Система' => ['erp-bus', 'media', 'settings', 'roles'],
    ];

    protected array $resourceLabels = [
        'products' => 'Товары', 'categories' => 'Категории', 'brands' => 'Бренды',
        'product-models' => 'Модели', 'attributes' => 'Атрибуты',
        'attribute-groups' => 'Группы атрибутов', 'size-charts' => 'Размерные сетки',
        'product-barcodes' => 'Штрихкоды', 'certificates' => 'Сертификаты',
        'product-exports' => 'Выгрузки', 'warehouses' => 'Склады', 'regions' => 'Регионы',
        'orders' => 'Заказы', 'carts' => 'Корзины', 'returns' => 'Возвраты',
        'shipments' => 'Реализации', 'favorites' => 'Избранное', 'wishlist' => 'Список желаний',
        'promotions' => 'Акции', 'product-selections' => 'Подборки',
        'users' => 'Пользователи', 'user-questionnaires' => 'Анкеты',
        'companies' => 'Компании', 'company-bank-accounts' => 'Банк. счета',
        'delivery-addresses' => 'Адреса доставки', 'currencies' => 'Валюты',
        'contractor-balances' => 'Балансы', 'individual-prices' => 'Инд. цены',
        'articles' => 'Статьи', 'brand-stories' => 'О брендах', 'news' => 'Новости',
        'faqs' => 'FAQ', 'banners' => 'Баннеры', 'pages' => 'Страницы',
        'stories' => 'Истории', 'tags' => 'Теги', 'erp-bus' => 'Шина ERP',
        'media' => 'Медиа', 'settings' => 'Настройки', 'roles' => 'Роли',
    ];

    protected array $actionLabels = [
        'view' => 'Просмотр', 'create' => 'Создание',
        'edit' => 'Редактирование', 'delete' => 'Удаление',
    ];

    public function index(Request $request)
    {
        $roles = Role::withCount(['permissions', 'users'])
            ->orderBy('name')
            ->paginate($request->input('per_page', 15));

        return Inertia::render('Admin/Pages/Roles/Index', [
            'roles' => $roles,
            'filters' => $request->only(['per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Roles/Create', [
            'permissionGroups' => $this->buildPermissionTree(),
            'actionLabels' => $this->actionLabels,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);
        $role->syncPermissions($validated['permissions'] ?? []);

        return $this->redirectAfterSave($request, 'admin.roles.index', 'admin.roles.edit', $role, 'Роль успешно создана');
    }

    public function edit(Role $role)
    {
        return Inertia::render('Admin/Pages/Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->toArray(),
                'users_count' => $role->users()->count(),
            ],
            'permissionGroups' => $this->buildPermissionTree(),
            'actionLabels' => $this->actionLabels,
        ]);
    }

    public function update(Request $request, Role $role)
    {
        if ($role->name === 'super-admin') {
            return back()->withErrors(['name' => 'Нельзя изменить роль «super-admin».']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions'] ?? []);

        return $this->redirectAfterSave($request, 'admin.roles.index', 'admin.roles.edit', $role, 'Роль успешно обновлена');
    }

    public function destroy(Role $role)
    {
        if ($role->name === 'super-admin') {
            return back()->with('error', 'Нельзя удалить роль «super-admin».');
        }

        $role->delete();

        return redirect()->route('admin.roles.index')->with('success', 'Роль успешно удалена');
    }

    /**
     * Построить дерево прав для UI матрицы.
     */
    protected function buildPermissionTree(): array
    {
        $allPermissions = Permission::where('guard_name', 'web')->pluck('name')->toArray();
        $tree = [];

        foreach ($this->permissionGroups as $groupLabel => $resources) {
            $groupItems = [];
            foreach ($resources as $resource) {
                $actions = [];
                foreach (['view', 'create', 'edit', 'delete'] as $action) {
                    $perm = "{$resource}.{$action}";
                    if (in_array($perm, $allPermissions)) {
                        $actions[] = $action;
                    }
                }
                if (!empty($actions)) {
                    $groupItems[] = [
                        'resource' => $resource,
                        'label' => $this->resourceLabels[$resource] ?? $resource,
                        'actions' => $actions,
                    ];
                }
            }
            if (!empty($groupItems)) {
                $tree[] = [
                    'group' => $groupLabel,
                    'items' => $groupItems,
                ];
            }
        }

        return $tree;
    }
}
