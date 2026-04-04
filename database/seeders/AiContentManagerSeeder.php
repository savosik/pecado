<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AiContentManagerSeeder extends Seeder
{
    public function run(): void
    {
        // Создаём или находим сервисного пользователя-бота
        $user = User::firstOrCreate(
            ['email' => 'ai-content-bot@pecado.ru'],
            [
                'name' => 'AI Content Bot',
                'password' => Hash::make(bin2hex(random_bytes(32))),
            ]
        );

        // Удаляем старые токены бота
        $user->tokens()->delete();

        // Создаём новый Sanctum-токен
        $token = $user->createToken('ai-content-agent', ['*']);

        $this->command->newLine();
        $this->command->info('╔══════════════════════════════════════════════════════╗');
        $this->command->info('║  AI Content Manager — Token Created                  ║');
        $this->command->info('╠══════════════════════════════════════════════════════╣');
        $this->command->info('║  User: ' . $user->email);
        $this->command->info('║  Token: ' . $token->plainTextToken);
        $this->command->info('╠══════════════════════════════════════════════════════╣');
        $this->command->warn('║  ⚠ Сохраните токен! Он показывается только один раз. ║');
        $this->command->info('╚══════════════════════════════════════════════════════╝');
        $this->command->newLine();
    }
}
