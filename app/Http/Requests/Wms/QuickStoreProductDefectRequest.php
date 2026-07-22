<?php

namespace App\Http\Requests\Wms;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Быстрый приём некондиции: как обычное заведение, но фото обязательны —
 * экран быстрого приёма не даёт сохранить партию без снимка дефекта.
 */
class QuickStoreProductDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('wms-defects.create') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'defect_description' => ['required', 'string', 'min:3', 'max:2000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['file', 'image', 'mimes:jpeg,png,webp,gif', 'max:20480'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $warehouseId = $this->input('warehouse_id');

                if (! $warehouseId) {
                    return;
                }

                $isDefectWarehouse = Warehouse::query()
                    ->whereKey($warehouseId)
                    ->where('is_defect', true)
                    ->exists();

                if (! $isDefectWarehouse) {
                    $validator->errors()->add('warehouse_id', 'Некондицию можно заводить только на складе некондиции.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Отсканируйте товар.',
            'defect_description.required' => 'Выберите или впишите дефект.',
            'defect_description.min' => 'Описание дефекта слишком короткое.',
            'quantity.min' => 'Количество должно быть не меньше 1.',
            'photos.required' => 'Сфотографируйте дефект — без фото партию не принять.',
            'photos.min' => 'Нужно хотя бы одно фото дефекта.',
            'photos.*.image' => 'Загружать можно только изображения.',
            'photos.*.mimes' => 'Допустимые форматы: JPEG, PNG, WEBP, GIF.',
            'photos.*.max' => 'Размер фотографии не должен превышать 20 МБ.',
        ];
    }
}
