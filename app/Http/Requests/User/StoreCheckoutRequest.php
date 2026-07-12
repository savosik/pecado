<?php

namespace App\Http\Requests\User;

use App\Enums\DeliveryMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Если способ доставки не передан — считаем «доставка» (обратная совместимость).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('delivery_method')) {
            $this->merge(['delivery_method' => DeliveryMethod::DELIVERY->value]);
        }
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'company_id' => [
                'required',
                'integer',
                "exists:companies,id,user_id,{$userId},deleted_at,NULL",
            ],
            'delivery_method' => ['required', Rule::enum(DeliveryMethod::class)],
            // При самовывозе адрес не нужен — исключается из validated()
            'delivery_address' => [
                'exclude_if:delivery_method,'.DeliveryMethod::PICKUP->value,
                'required',
                'string',
                'max:1000',
            ],
            // Сохранение нового адреса в список пользователя (только при доставке).
            'save_address' => [
                'exclude_if:delivery_method,'.DeliveryMethod::PICKUP->value,
                'sometimes',
                'boolean',
            ],
            'address_name' => [
                'exclude_if:delivery_method,'.DeliveryMethod::PICKUP->value,
                'nullable',
                'string',
                'max:255',
            ],
            'address_make_default' => [
                'exclude_if:delivery_method,'.DeliveryMethod::PICKUP->value,
                'sometimes',
                'boolean',
            ],
            'address_data' => [
                'exclude_if:delivery_method,'.DeliveryMethod::PICKUP->value,
                'nullable',
                'array',
            ],
            'comment' => ['nullable', 'string', 'max:5000'],
            'manager_comment' => ['nullable', 'string'],
            'warehouse_comment' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_id.required' => 'Выберите компанию.',
            'company_id.exists' => 'Выбранная компания не найдена.',
            'delivery_method.required' => 'Выберите способ доставки.',
            'delivery_method.Illuminate\Validation\Rules\Enum' => 'Недопустимый способ доставки.',
            'delivery_address.required' => 'Укажите адрес доставки.',
            'delivery_address.max' => 'Адрес не должен превышать 1000 символов.',
            'comment.max' => 'Комментарий не должен превышать 5000 символов.',
        ];
    }
}
