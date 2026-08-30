<?php

namespace App\Http\Requests\Crm;

use App\Services\Payroll\PayrollCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Сохранение параметров компонента для менеджера: постоянно (без месяца) или на месяц.
 *
 * Форма присылает полный набор параметров компонента; что из него отклонение,
 * решает резолвер — здесь только форма запроса и доменная валидация.
 */
class StorePayrollParamsRequest extends FormRequest
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
            'manager_id' => ['required', 'integer', 'min:1', Rule::exists('personal_managers', 'id')],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'component' => ['required', 'string', Rule::in(app(PayrollCatalog::class)->keys())],
            'params' => ['required', 'array'],
            'comment' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manager_id.required' => 'Не указан менеджер.',
            'manager_id.exists' => 'Такого менеджера нет.',
            'month.regex' => 'Месяц должен быть в формате ГГГГ-ММ.',
            'component.required' => 'Не указан компонент.',
            'component.in' => 'Такого компонента нет.',
            'params.required' => 'Параметры пусты.',
            'params.array' => 'Параметры должны быть объектом.',
            'comment.max' => 'Пояснение не может быть длиннее 255 символов.',
        ];
    }
}
