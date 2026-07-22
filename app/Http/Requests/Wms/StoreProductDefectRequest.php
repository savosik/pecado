<?php

namespace App\Http\Requests\Wms;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductDefectRequest extends FormRequest
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
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['file', 'image', 'mimes:jpeg,png,webp,gif', 'max:20480'],
        ];
    }

    /**
     * Партию можно заводить только на складе некондиции — иначе она разъедется
     * с остатками обычных складов, которые ведёт 1С.
     */
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
                    $validator->errors()->add(
                        'warehouse_id',
                        'Некондицию можно заводить только на складе некондиции.'
                    );
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
            'product_id.required' => 'Выберите товар.',
            'product_id.exists' => 'Такой товар не найден.',
            'warehouse_id.required' => 'Выберите склад.',
            'warehouse_id.exists' => 'Такой склад не найден.',
            'defect_description.required' => 'Опишите дефект.',
            'defect_description.min' => 'Описание дефекта слишком короткое (минимум 3 символа).',
            'defect_description.max' => 'Описание дефекта слишком длинное (максимум 2000 символов).',
            'quantity.required' => 'Укажите количество.',
            'quantity.integer' => 'Количество должно быть целым числом.',
            'quantity.min' => 'Количество должно быть не меньше 1.',
            'quantity.max' => 'Количество слишком большое (максимум 10 000).',
            'photos.max' => 'Можно загрузить не больше 10 фотографий.',
            'photos.*.image' => 'Загружать можно только изображения.',
            'photos.*.mimes' => 'Допустимые форматы: JPEG, PNG, WEBP, GIF.',
            'photos.*.max' => 'Размер фотографии не должен превышать 20 МБ.',
        ];
    }
}
