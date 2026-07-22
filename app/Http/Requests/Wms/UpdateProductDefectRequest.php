<?php

namespace App\Http\Requests\Wms;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Models\ProductDefect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Правка партии некондиции кладовщиком.
 *
 * Товар и склад менять нельзя: на партию уже могли оформить заказ, и подмена
 * товара молча изменила бы состав чужого заказа. Ошиблись — удалите партию
 * и заведите заново (удаление запрещено при наличии резерва).
 */
class UpdateProductDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('wms-defects.edit') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'defect_description' => ['required', 'string', 'min:3', 'max:2000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['file', 'image', 'mimes:jpeg,png,webp,gif', 'max:20480'],
            'removed_media_ids' => ['nullable', 'array'],
            'removed_media_ids.*' => ['integer'],
        ];
    }

    /**
     * Количество нельзя опустить ниже уже зарезервированного заказами:
     * иначе клиент купил бы то, чего физически нет.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $defect = $this->route('defect');

                if (! $defect instanceof ProductDefect) {
                    return;
                }

                $quantity = (int) $this->input('quantity');
                $reserved = app(DefectStockServiceInterface::class)->reserved($defect);

                if ($quantity < $reserved) {
                    $validator->errors()->add(
                        'quantity',
                        "Нельзя указать меньше {$reserved} шт: столько уже зарезервировано заказами."
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
