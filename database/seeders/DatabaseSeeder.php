<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            WarehouseAndRegionSeeder::class,
        ]);

        $this->call(RolesAndPermissionsSeeder::class);

        // Создание тестовых аккаунтов (docs/DEV_SERVER_CREDENTIALS.md)
        $accounts = [
            // Super-admin — полный доступ
            ['email' => 'admin@pecado.ru',           'name' => 'Admin',                'password' => 'Admin2024!',     'role' => 'super-admin'],
            ['email' => 'savosik@pecado.ru',          'name' => 'Savosik (Dev Admin)',  'password' => 'Savosik2024!',   'role' => 'super-admin'],
            // Контент-менеджер — статьи, новости, баннеры, страницы
            ['email' => 'content@pecado.ru',          'name' => 'Контент-менеджер',     'password' => 'Content2024!',   'role' => 'content-manager'],
            // Менеджер продаж — заказы, клиенты, возвраты + CRM
            ['email' => 'sales@pecado.ru',            'name' => 'Менеджер продаж',      'password' => 'Sales2024!',     'role' => 'sales-manager'],
            // РОП — только CRM: в админку намеренно не пускает (см. EnsureUserIsAdmin)
            ['email' => 'rop@pecado.ru',              'name' => 'Медведев Сергей (РОП)', 'password' => 'Rop2024!',      'role' => 'sales-head'],
            // Склад — только /wms, в админку и CRM намеренно не пускают
            ['email' => 'sklad-head@pecado.ru',       'name' => 'Начальник склада',      'password' => 'Sklad2024!',    'role' => 'warehouse-head'],
            ['email' => 'sklad1@pecado.ru',           'name' => 'Кладовщик 1',           'password' => 'Sklad2024!',    'role' => 'storekeeper'],
            // Каталоговед — товары, категории, бренды, атрибуты
            ['email' => 'catalog@pecado.ru',          'name' => 'Каталоговед',          'password' => 'Catalog2024!',   'role' => 'catalogist'],
        ];

        foreach ($accounts as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => $data['password'],
                    'status' => UserStatus::ACTIVE,
                ]
            );
            $user->syncRoles([$data['role']]);
        }
    }
}
