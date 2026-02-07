<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // General
            [
                'key' => 'site_name',
                'value' => 'Pecado',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Название сайта',
            ],
            [
                'key' => 'admin_email',
                'value' => 'admin@pecado.com',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Email администратора для уведомлений',
            ],
            [
                'key' => 'items_per_page',
                'value' => '15',
                'type' => 'integer',
                'group' => 'general',
                'description' => 'Количество элементов на странице по умолчанию',
            ],
            [
                'key' => 'maintenance_mode',
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'general',
                'description' => 'Режим технических работ',
            ],
            
            // Email
            [
                'key' => 'smtp_host',
                'value' => 'smtp.example.com',
                'type' => 'string',
                'group' => 'email',
                'description' => 'SMTP сервер',
            ],
            [
                'key' => 'smtp_port',
                'value' => '587',
                'type' => 'integer',
                'group' => 'email',
                'description' => 'SMTP порт',
            ],
            [
                'key' => 'smtp_encryption',
                'value' => 'tls',
                'type' => 'string',
                'group' => 'email',
                'description' => 'Тип шифрования (tls/ssl)',
            ],
            
            // Limits
            [
                'key' => 'max_upload_size',
                'value' => '10240',
                'type' => 'integer',
                'group' => 'limits',
                'description' => 'Максимальный размер загружаемого файла (KB)',
            ],
            [
                'key' => 'max_cart_items',
                'value' => '100',
                'type' => 'integer',
                'group' => 'limits',
                'description' => 'Максимальное количество товаров в корзине',
            ],
            
            // API
            [
                'key' => 'api_enabled',
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'api',
                'description' => 'Включить API',
            ],
            [
                'key' => 'api_rate_limit',
                'value' => '60',
                'type' => 'integer',
                'group' => 'api',
                'description' => 'Лимит запросов API в минуту',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
