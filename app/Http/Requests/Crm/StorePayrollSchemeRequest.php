<?php

namespace App\Http\Requests\Crm;

use App\Services\Payroll\PayrollCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Новая версия схемы отдела: набор компонентов, их включённость и умолчания с месяца.
 */
class StorePayrollSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'title' => ['nullable', 'string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:255'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.key' => ['required', 'string', Rule::in(app(PayrollCatalog::class)->keys())],
            'components.*.enabled' => ['nullable', 'boolean'],
            'components.*.defaults' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_from.required' => 'Укажите месяц, с которого действует версия.',
            'effective_from.regex' => 'Месяц должен быть в формате ГГГГ-ММ.',
            'title.max' => 'Название не может быть длиннее 120 символов.',
            'comment.max' => 'Комментарий не может быть длиннее 255 символов.',
            'components.required' => 'В схеме должен быть хотя бы один компонент.',
            'components.*.key.required' => 'У компонента нет ключа.',
            'components.*.key.in' => 'Такого компонента нет в каталоге.',
        ];
    }
}
