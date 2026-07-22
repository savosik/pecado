<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Установка цены уценки закупщиком.
 *
 * Цена — абсолютная сумма в рублях за штуку; индивидуальные цены клиента и
 * скидки к ней не применяются (см. docs-erp/content/rules/pricing-model.md).
 */
class UpdateDefectPriceRequest extends FormRequest
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
            'price' => ['required', 'numeric', 'gt:0', 'max:9999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.required' => 'Укажите цену уценки.',
            'price.numeric' => 'Цена должна быть числом.',
            'price.gt' => 'Цена должна быть больше нуля.',
            'price.max' => 'Слишком большая цена.',
        ];
    }
}
