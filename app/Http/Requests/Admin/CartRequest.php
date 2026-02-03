<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CartRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'user_id' => 'required|exists:users,id',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:cart_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes(): array
    {
        return [
            'name' => 'Название корзины',
            'user_id' => 'Пользователь',
            'items' => 'Товары',
            'items.*.product_id' => 'Товар',
            'items.*.quantity' => 'Количество',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Выберите пользователя',
            'user_id.exists' => 'Пользователь не найден',
            'items.required' => 'Добавьте хотя бы один товар',
            'items.min' => 'Добавьте хотя бы один товар',
            'items.*.product_id.required' => 'Выберите товар',
            'items.*.product_id.exists' => 'Товар не найден',
            'items.*.quantity.required' => 'Укажите количество',
            'items.*.quantity.min' => 'Минимальное количество: 1',
        ];
    }
}
