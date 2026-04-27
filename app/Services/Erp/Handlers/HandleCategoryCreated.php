<?php

namespace App\Services\Erp\Handlers;

use App\Models\Category;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * US-13: Обработка события category.created из 1С.
 * Создаёт или обновляет категорию (вид номенклатуры) в каталоге сайта.
 * Идемпотентен: повторная обработка обновляет существующую запись.
 */
class HandleCategoryCreated
{
    /**
     * Имя shared-блокировки для NestedSet категорий.
     *
     * Why: erp-catalog-consumer работает в 6 потоков (см. supervisor/worker.conf),
     * а перемещение узла NestedSet перерасчитывает _lft/_rgt у соседних узлов.
     * Параллельные save() с изменением parent_id ломают индексы дерева
     * (получаем дубли _lft и узлы в чужих поддеревьях).
     */
    private const TREE_LOCK_KEY = 'erp:category-tree';

    private const TREE_LOCK_TTL = 60;

    private const TREE_LOCK_WAIT = 30;

    public function handle(array $payload): void
    {
        try {
            Cache::lock(self::TREE_LOCK_KEY, self::TREE_LOCK_TTL)
                ->block(self::TREE_LOCK_WAIT, function () use ($payload) {
                    DB::transaction(fn () => $this->upsert($payload));
                });
        } catch (LockTimeoutException $e) {
            Log::warning('category.created: не удалось получить lock на дерево категорий', [
                'uuid' => $payload['uuid'] ?? null,
                'wait_seconds' => self::TREE_LOCK_WAIT,
            ]);

            throw $e;
        }
    }

    private function upsert(array $payload): void
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

        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $parent = null;
        if ($parentUuid) {
            $parent = Category::where('uuid', $parentUuid)->first();
            if (! $parent) {
                Log::warning('category.created: родительская категория не найдена', [
                    'uuid' => $uuid,
                    'parent_uuid' => $parentUuid,
                ]);
            }
        }

        $existing = Category::where('uuid', $uuid)->first();

        $slug = $existing?->slug;
        if (! $slug) {
            $slug = $this->generateUniqueSlug($name, $existing?->id);
        }

        $attributes = [
            'name' => $name,
            'slug' => $slug,
            'external_id' => $uuid,
            'is_active' => $isActive,
        ];

        if ($existing) {
            $existing->fill($attributes);
            $this->placeInTree($existing, $parent);
        } else {
            $category = new Category(array_merge(['uuid' => $uuid], $attributes));
            $this->placeInTree($category, $parent);
        }

        Log::info('category.created: категория создана/обновлена', [
            'uuid' => $uuid,
            'name' => $name,
            'slug' => $slug,
            'is_active' => $isActive,
            'parent_id' => $parent?->id,
        ]);

        $this->assertTreeIntegrity($uuid);
    }

    /**
     * Помещает узел в дерево через nested-set API.
     *
     * Why: прямое присваивание parent_id внутри updateOrCreate работает
     * только при отсутствии гонок. Явный API kalnoy/nestedset (saveAsRoot,
     * appendToNode) даёт корректный atomic-перерасчёт _lft/_rgt в рамках
     * транзакции.
     */
    private function placeInTree(Category $category, ?Category $parent): void
    {
        if ($parent === null) {
            $category->saveAsRoot();

            return;
        }

        $category->appendToNode($parent)->save();
    }

    /**
     * Раннее обнаружение поломки дерева. Дешёвая проверка после каждой
     * операции — если что-то рассогласовано, видно сразу в логах, а не
     * через симптом «крошки показывают не того предка».
     */
    private function assertTreeIntegrity(string $uuid): void
    {
        // @phpstan-ignore-next-line staticMethod.notFound (kalnoy/nestedset NodeTrait)
        $errors = Category::countErrors();
        $hasErrors = ($errors['oddness'] ?? 0)
            + ($errors['duplicates'] ?? 0)
            + ($errors['wrong_parent'] ?? 0)
            + ($errors['missing_parent'] ?? 0);

        if ($hasErrors > 0) {
            Log::critical('category.created: NestedSet-дерево повреждено после обработки', [
                'uuid' => $uuid,
                'errors' => $errors,
            ]);
        }
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
