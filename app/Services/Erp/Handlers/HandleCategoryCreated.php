<?php

namespace App\Services\Erp\Handlers;

use App\Models\Category;
use Illuminate\Support\Facades\Log;

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
        $isGroup = (bool) ($payload['is_group'] ?? false);

        if (!$uuid || !$name) {
            Log::warning('category.created: отсутствуют обязательные поля uuid или name', [
                'payload' => $payload,
            ]);
            return;
        }

        // Найти родительскую категорию по uuid из 1С
        $parentId = null;
        if ($parentUuid) {
            $parent = Category::where('uuid', $parentUuid)->first();
            if ($parent) {
                $parentId = $parent->id;
            } else {
                Log::warning('category.created: родительская категория не найдена', [
                    'uuid'        => $uuid,
                    'parent_uuid' => $parentUuid,
                ]);
            }
        }

        // Идемпотентный upsert по uuid из 1С
        $category = Category::updateOrCreate(
            ['uuid' => $uuid],
            [
                'name'     => $name,
                'is_group' => $isGroup,
                'parent_id' => $parentId,
            ]
        );

        Log::info('category.created: категория создана/обновлена', [
            'uuid'     => $uuid,
            'name'     => $name,
            'is_group' => $isGroup,
            'parent_id' => $parentId,
            'id'       => $category->id,
        ]);
    }
}
