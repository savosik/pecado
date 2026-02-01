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
        User::create([
            'name' => 'Admin',
            'email' => 'admin@pecado.test',
            'password' => 'password', // будет автоматически хешировано
            'is_admin' => true,
        ]);
    }
}
