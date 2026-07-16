<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Все ресурсы админки и их доступные действия.
     */
    protected array $resources = [
        // Каталог
        'products' => ['view', 'create', 'edit', 'delete'],
        'categories' => ['view', 'create', 'edit', 'delete'],
        'brands' => ['view', 'create', 'edit', 'delete'],
        'product-models' => ['view', 'create', 'edit', 'delete'],
        'attributes' => ['view', 'create', 'edit', 'delete'],
        'attribute-groups' => ['view', 'create', 'edit', 'delete'],
        'size-charts' => ['view', 'create', 'edit', 'delete'],
        'product-barcodes' => ['view', 'create', 'edit', 'delete'],
        'certificates' => ['view', 'create', 'edit', 'delete'],
        'product-exports' => ['view', 'create', 'edit', 'delete'],

        // Склады
        'warehouses' => ['view', 'create', 'edit', 'delete'],
        'regions' => ['view', 'create', 'edit', 'delete'],

        // Продажи
        'orders' => ['view', 'create', 'edit', 'delete'],
        'carts' => ['view', 'create', 'edit', 'delete'],
        'returns' => ['view', 'create', 'edit', 'delete'],
        'shipments' => ['view', 'delete'],
        'favorites' => ['view', 'create', 'edit', 'delete'],
        'wishlist' => ['view', 'create', 'edit', 'delete'],

        // Маркетинг
        'promotions' => ['view', 'create', 'edit', 'delete'],
        'product-selections' => ['view', 'create', 'edit', 'delete'],

        // Пользователи
        'users' => ['view', 'create', 'edit', 'delete'],
        'user-questionnaires' => ['view', 'create', 'edit', 'delete'],
        'client-statuses' => ['view', 'create', 'edit', 'delete'],
        'personal-managers' => ['view', 'create', 'edit', 'delete'],
        'companies' => ['view', 'create', 'edit', 'delete'],
        'company-bank-accounts' => ['view', 'create', 'edit', 'delete'],
        'delivery-addresses' => ['view', 'create', 'edit', 'delete'],

        // Финансы
        'currencies' => ['view', 'create', 'edit', 'delete'],
        'contractor-balances' => ['view', 'create', 'edit', 'delete'],
        'individual-prices' => ['view', 'create', 'edit', 'delete'],

        // Контент
        'articles' => ['view', 'create', 'edit', 'delete'],
        'brand-stories' => ['view', 'create', 'edit', 'delete'],
        'news' => ['view', 'create', 'edit', 'delete'],
        'faqs' => ['view', 'create', 'edit', 'delete'],
        'banners' => ['view', 'create', 'edit', 'delete'],
        'pages' => ['view', 'create', 'edit', 'delete'],
        'stories' => ['view', 'create', 'edit', 'delete'],
        'menu-items' => ['view', 'create', 'edit', 'delete'],
        'user-questions' => ['view', 'edit', 'delete'],

        // Теги
        'tags' => ['view', 'create', 'edit', 'delete'],

        // CRM — права домена /crm/. Префикс `crm-` значим: по нему
        // User::hasAdminAccess() отличает CRM-only сотрудника от админского.
        'crm-dashboard' => ['view'],
        'crm-clients' => ['view', 'edit'],
        'crm-clients-all' => ['view'],
        'crm-team' => ['view'],
        'crm-analytics' => ['view'],

        // Система
        'erp-bus' => ['view'],
        'media' => ['view', 'delete'],
        'settings' => ['view', 'edit'],
        'roles' => ['view', 'create', 'edit', 'delete'],
    ];

    /**
     * Русские названия ресурсов для отображения.
     */
    protected array $resourceLabels = [
        'products' => 'Товары',
        'categories' => 'Категории',
        'brands' => 'Бренды',
        'product-models' => 'Модели товаров',
        'attributes' => 'Атрибуты',
        'attribute-groups' => 'Группы атрибутов',
        'size-charts' => 'Размерные сетки',
        'product-barcodes' => 'Штрихкоды',
        'certificates' => 'Сертификаты',
        'product-exports' => 'Выгрузки',
        'warehouses' => 'Склады',
        'regions' => 'Регионы',
        'orders' => 'Заказы',
        'carts' => 'Корзины',
        'returns' => 'Возвраты',
        'shipments' => 'Реализации',
        'favorites' => 'Избранное',
        'wishlist' => 'Список желаний',
        'promotions' => 'Акции',
        'product-selections' => 'Подборки',
        'users' => 'Пользователи',
        'user-questionnaires' => 'Анкеты',
        'client-statuses' => 'Статусы клиентов',
        'personal-managers' => 'Персональные менеджеры',
        'companies' => 'Компании',
        'company-bank-accounts' => 'Банковские счета',
        'delivery-addresses' => 'Адреса доставки',
        'currencies' => 'Валюты',
        'contractor-balances' => 'Балансы контрагентов',
        'individual-prices' => 'Инд. цены',
        'articles' => 'Статьи',
        'brand-stories' => 'О брендах',
        'news' => 'Новости',
        'faqs' => 'FAQ',
        'banners' => 'Баннеры',
        'pages' => 'Страницы',
        'stories' => 'Истории',
        'menu-items' => 'Меню',
        'user-questions' => 'Вопросы пользователей',
        'tags' => 'Теги',
        'crm-dashboard' => 'CRM: Рабочий стол',
        'crm-clients' => 'CRM: Мои клиенты',
        'crm-clients-all' => 'CRM: Клиенты всего отдела',
        'crm-team' => 'CRM: Команда',
        'crm-analytics' => 'CRM: Отчёты продаж',
        'erp-bus' => 'Шина ERP',
        'media' => 'Медиа',
        'settings' => 'Настройки',
        'roles' => 'Роли',
    ];

    /**
     * Предустановленные роли и их ресурсы.
     */
    protected array $presetRoles = [
        'super-admin' => [
            'label' => 'Супер-админ',
            'resources' => '*', // Все ресурсы
        ],
        'content-manager' => [
            'label' => 'Контент-менеджер',
            'resources' => [
                'articles', 'brand-stories', 'news', 'faqs',
                'banners', 'pages', 'stories', 'tags', 'media',
                'menu-items', 'user-questions',
            ],
        ],
        'sales-manager' => [
            'label' => 'Менеджер продаж',
            'resources' => [
                'orders', 'carts', 'returns', 'shipments',
                'favorites', 'wishlist',
                // CRM: свои клиенты (те, что закреплены за его карточкой менеджера)
                'crm-dashboard', 'crm-clients', 'crm-analytics',
            ],
        ],
        'sales-manager-crm' => [
            'label' => 'Менеджер продаж (только CRM)',
            'resources' => [
                // Только CRM: в /admin роль намеренно не пускает.
                // Для менеджеров, которым нужны свои клиенты, но не нужна админка.
                'crm-dashboard', 'crm-clients', 'crm-analytics',
            ],
        ],
        'sales-head' => [
            'label' => 'Руководитель отдела продаж',
            'resources' => [
                // Только CRM: в /admin роль намеренно не пускает.
                'crm-dashboard', 'crm-clients', 'crm-clients-all', 'crm-team', 'crm-analytics',
            ],
        ],
        'catalogist' => [
            'label' => 'Каталоговед',
            'resources' => [
                'products', 'categories', 'brands', 'product-models',
                'attributes', 'attribute-groups', 'size-charts',
                'product-barcodes', 'certificates', 'product-exports',
            ],
        ],
    ];

    public function run(): void
    {
        // Сбрасываем кеш прав
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Создаём все permissions
        $allPermissions = [];
        foreach ($this->resources as $resource => $actions) {
            foreach ($actions as $action) {
                $permissionName = "{$resource}.{$action}";
                $permission = Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
                $allPermissions[$permissionName] = $permission;
            }
        }

        $this->command->info('Создано '.count($allPermissions).' прав.');

        // 2. Создаём роли и назначаем permissions
        foreach ($this->presetRoles as $roleName => $config) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            if ($config['resources'] === '*') {
                // Super-admin получает все permissions
                $role->syncPermissions(array_values($allPermissions));
                $this->command->info("Роль «{$config['label']}» — все права ({$role->permissions()->count()}).");
            } else {
                // Собираем permissions для конкретных ресурсов
                $rolePermissions = [];
                foreach ($config['resources'] as $resource) {
                    if (! isset($this->resources[$resource])) {
                        continue;
                    }
                    foreach ($this->resources[$resource] as $action) {
                        $key = "{$resource}.{$action}";
                        if (isset($allPermissions[$key])) {
                            $rolePermissions[] = $allPermissions[$key];
                        }
                    }
                }
                $role->syncPermissions($rolePermissions);
                $this->command->info("Роль «{$config['label']}» — {$role->permissions()->count()} прав.");
            }
        }

        // 3. Назначаем super-admin всем текущим пользователям с is_admin = true (если колонка ещё существует)
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'is_admin')) {
            $adminUsers = User::where('is_admin', true)->get();
            $superAdminRole = Role::findByName('super-admin', 'web');

            foreach ($adminUsers as $user) {
                if (! $user->hasRole('super-admin')) {
                    $user->assignRole($superAdminRole);
                }
            }

            $this->command->info("Роль «Супер-админ» назначена {$adminUsers->count()} пользователям с is_admin=true.");
        } else {
            $this->command->info('Колонка is_admin удалена — миграция пользователей пропущена.');
        }
    }
}
