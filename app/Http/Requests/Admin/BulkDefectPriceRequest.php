<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Массовая установка цен уценки от справочной цены клиента.
 *
 * Сами цены с фронта не принимаем — только список партий и размер скидки:
 * справочную цену бэкенд пересчитывает заново, иначе в базу можно было бы
 * положить любое число из браузера.
 */
class BulkDefectPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('defects.price') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'exists:product_defects,id'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Выберите хотя бы одну партию.',
            'ids.array' => 'Выберите хотя бы одну партию.',
            'ids.min' => 'Выберите хотя бы одну партию.',
            'ids.max' => 'За один раз можно обработать не больше 200 партий.',
            'ids.*.integer' => 'Некорректная партия в списке.',
            'ids.*.exists' => 'Одной из выбранных партий уже не существует.',
            'discount_percent.numeric' => 'Скидка должна быть числом.',
            'discount_percent.min' => 'Скидка не может быть отрицательной.',
            'discount_percent.max' => 'Скидка не может быть больше 99 %.',
        ];
    }
}
