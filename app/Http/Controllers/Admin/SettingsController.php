<?php

namespace App\Http\Controllers\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        // Получаем все настройки сгруппированные по group
        $settings = Setting::all()->groupBy('group');

        // Преобразуем в удобный формат [key => value]
        $groupedSettings = $settings->map(function ($group) {
            return $group->mapWithKeys(function ($setting) {
                return [
                    $setting->key => [
                        'value' => $setting->value,
                        'type' => $setting->type,
                        'description' => $setting->description,
                    ]
                ];
            });
        });

        return Inertia::render('Admin/Pages/Settings/Index', [
            'settings' => $groupedSettings,
        ]);
    }

    public function update(Request $request)
    {
        // Получаем все текущие настройки для валидации
        $allSettings = Setting::all()->keyBy('key');

        // Динамическая валидация на основе типов
        $rules = [];
        foreach ($request->all() as $key => $value) {
            if (isset($allSettings[$key])) {
                $type = $allSettings[$key]->type;
                $rules[$key] = match ($type) {
                    'integer' => 'nullable|integer',
                    'boolean' => 'nullable|boolean',
                    'json' => 'nullable|json',
                    default => 'nullable|string|max:65535',
                };
            }
        }

        $validated = $request->validate($rules);

        // Обновляем настройки
        foreach ($validated as $key => $value) {
            if (isset($allSettings[$key])) {
                $setting = $allSettings[$key];
                $setting->value = $value;
                $setting->save();
            }
        }

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Настройки успешно обновлены');
    }

    /**
     * Генерация Sanctum-токена для сервисного пользователя AI Content Bot.
     */
    public function generateContentToken()
    {
        // Создаём или находим сервисного пользователя
        $user = User::firstOrCreate(
            ['email' => 'ai-content-bot@pecado.ru'],
            [
                'name' => 'AI Content Bot',
                'password' => Hash::make(bin2hex(random_bytes(32))),
            ]
        );

        // Удаляем все старые токены
        $user->tokens()->delete();

        // Создаём новый Sanctum-токен
        $token = $user->createToken('ai-content-agent', ['*']);

        return response()->json([
            'token' => $token->plainTextToken,
        ]);
    }
}
