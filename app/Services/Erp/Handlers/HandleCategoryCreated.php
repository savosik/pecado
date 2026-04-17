<?php

namespace App\Services\Erp\Handlers;

use App\Models\Category;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * US-13: Обработка события category.created из 1С.
 * Создаёт или обновляет категорию (вид номенклатуры) в каталоге сайта.
 * Идемпотентен: повторная обработка обновляет существующую запись.
 */
class HandleCategoryCreated
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $name = $payload['name'] ?? null;
        $parentUuid = $payload['parent_uuid'] ?? null;
        $isActive = (bool) ($payload['is_active'] ?? true);

        if (! $uuid || ! $name) {
            Log::warning('category.created: отсутствуют обязательные поля uuid или name', [
                'payload' => $payload,
            ]);

            return;
        }

        // Декодируем HTML-сущности в названии (1С может отправлять &quot; &amp; и т.п.)
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Найти родительскую категорию по uuid из 1С
        $parentId = null;
        if ($parentUuid) {
            $parent = Category::where('uuid', $parentUuid)->first();
            if ($parent) {
                $parentId = $parent->id;
            } else {
                Log::warning('category.created: родительская категория не найдена', [
                    'uuid' => $uuid,
                    'parent_uuid' => $parentUuid,
                ]);
            }
        }

        // Проверяем, существует ли уже категория с этим uuid
        $existing = Category::where('uuid', $uuid)->first();

        // Генерируем уникальный slug (только при создании новой категории или если slug пуст)
        $slug = $existing?->slug;
        if (! $slug) {
            $slug = $this->generateUniqueSlug($name, $existing?->id);
        }

        // Идемпотентный upsert по uuid из 1С
        $category = Category::updateOrCreate(
            ['uuid' => $uuid],
            [
                'name' => $name,
                'slug' => $slug,
                'external_id' => $uuid,
                'is_active' => $isActive,
                'parent_id' => $parentId,
            ]
        );

        Log::info('category.created: категория создана/обновлена', [
            'uuid' => $uuid,
            'name' => $name,
            'slug' => $slug,
            'is_active' => $isActive,
            'parent_id' => $parentId,
            'id' => $category->id,
        ]);
    }

    /**
     * Генерация уникального slug для категории.
     * При совпадении имён добавляет числовой суффикс (-2, -3, ...)
     */
    private function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $baseSlug = Str::slug($name) ?: 'category-'.Str::random(6);
        $slug = $baseSlug;
        $counter = 1;

        while (true) {
            $query = Category::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
            if (! $query->exists()) {
                break;
            }
            $counter++;
            $slug = $baseSlug.'-'.$counter;
        }

        return $slug;
    }
}
