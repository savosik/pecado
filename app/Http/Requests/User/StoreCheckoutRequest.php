<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'delivery_address_id' => [
                'nullable',
                'integer',
                "exists:delivery_addresses,id,user_id,{$userId}",
            ],
            'new_address' => ['nullable', 'string', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_id.required' => 'Выберите компанию.',
            'company_id.exists' => 'Выбранная компания не найдена.',
            'delivery_address_id.exists' => 'Выбранный адрес не найден.',
            'new_address.max' => 'Адрес не должен превышать 1000 символов.',
            'comment.max' => 'Комментарий не должен превышать 5000 символов.',
        ];
    }

    /**
     * Валидация: нужен либо delivery_address_id, либо new_address.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->filled('delivery_address_id') && !$this->filled('new_address')) {
                $validator->errors()->add('delivery_address_id', 'Укажите адрес доставки или введите новый.');
            }
        });
    }
}
