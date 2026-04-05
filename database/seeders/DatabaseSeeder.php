<?php

namespace Database\Seeders;

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
            SettingsSeeder::class,
            ProductExportSeeder::class,
            BannerSeeder::class,
            StorySeeder::class,
            ProductSeeder::class,
            ProductSelectionSeeder::class,
        ]);

        $this->call(RolesAndPermissionsSeeder::class);

        // Создание администраторов (DEV_SERVER_CREDENTIALS.md)
        $admins = [
            ['email' => 'admin@pecado.ru',    'name' => 'Admin',                'password' => 'Admin2024!'],
            ['email' => 'savosik@pecado.ru',   'name' => 'Savosik (Dev Admin)',  'password' => 'Savosik2024!'],
        ];

        foreach ($admins as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'     => $data['name'],
                    'password' => $data['password'],
                ]
            );
            $user->assignRole('super-admin');
        }
    }
}
