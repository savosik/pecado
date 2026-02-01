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
        ]);

        // Создание администратора
        User::firstOrCreate(
            ['email' => 'admin@pecado.test'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'is_admin' => true,
            ]
        );

        // Сиды для каталога
        \App\Models\Category::factory(10)->create();
        \App\Models\Brand::factory(5)->create();
        \App\Models\Product::factory(20)->create();
    }
}
