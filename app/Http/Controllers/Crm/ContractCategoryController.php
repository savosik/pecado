<?php

namespace App\Http\Controllers\Crm;

use App\Http\Requests\Crm\StoreContractCategoryRequest;
use App\Models\Contract;
use App\Models\ContractCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Категории реестра договоров — вкладки.
 *
 * Ведёт тот, кто вправе править договоры: заводить вкладку под новое юрлицо
 * или вид договора должен РОП, а не разработчик.
 */
class ContractCategoryController extends CrmController
{
    public function store(StoreContractCategoryRequest $request): JsonResponse
    {
        $category = ContractCategory::query()->create($this->attributes($request->validated()));

        return response()->json($this->payload($category), 201);
    }

    public function update(StoreContractCategoryRequest $request, ContractCategory $category): JsonResponse
    {
        $category->update($this->attributes($request->validated()));

        return response()->json($this->payload($category->refresh()));
    }

    /**
     * Удалить можно только пустую категорию: договоры без вкладки повисли бы.
     * Полную — отключают (is_active = false).
     */
    public function destroy(ContractCategory $category): JsonResponse
    {
        if (Contract::withTrashed()->where('category_id', $category->getKey())->exists()) {
            throw ValidationException::withMessages([
                'category' => 'В категории есть договоры — отключите её вместо удаления.',
            ]);
        }

        $category->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return [
            'name' => trim((string) $validated['name']),
            'description' => $validated['description'] ?? null,
            'organization_id' => $validated['organization_id'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ContractCategory $category): array
    {
        return [
            'id' => (int) $category->getKey(),
            'name' => $category->name,
            'description' => $category->description,
            'organization_id' => $category->organization_id,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
        ];
    }
}
