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
            'delivery_address' => ['required', 'string', 'max:1000'],
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
            'delivery_address.required' => 'Укажите адрес доставки.',
            'delivery_address.max' => 'Адрес не должен превышать 1000 символов.',
            'comment.max' => 'Комментарий не должен превышать 5000 символов.',
        ];
    }
}
