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

        // Создание администратора (DEV_SERVER_CREDENTIALS.md)
        $admin = User::updateOrCreate(
            ['email' => 'admin@pecado.ru'],
            [
                'name'     => 'Admin',
                'password' => 'Admin2024!',
            ]
        );
        $admin->assignRole('super-admin');
    }
}
