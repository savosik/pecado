<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Категория реестра — вкладка. Имя уникально: две вкладки «ООО Пекадо»
 * менеджер не различит.
 */
class StoreContractCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ignoreId = $this->route('category')?->getKey();

        return [
            'name' => ['required', 'string', 'max:191', Rule::unique('contract_categories', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:500'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название категории.',
            'name.max' => 'Название не длиннее 191 символа.',
            'name.unique' => 'Категория с таким названием уже есть.',
            'description.max' => 'Пояснение не длиннее 500 символов.',
            'organization_id.exists' => 'Организация не найдена.',
            'sort_order.integer' => 'Порядок — целое число.',
        ];
    }
}
