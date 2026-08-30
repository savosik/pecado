<?php

namespace App\Http\Requests\Crm;

use App\Models\PayrollManualAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ручная строка дохода: позиция доп. дохода или корректировка РОПа.
 */
class StorePayrollAdjustmentRequest extends FormRequest
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
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'component' => ['required', 'string', Rule::in(PayrollManualAdjustment::COMPONENTS)],
            'label' => ['required', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'price' => ['required', 'numeric', 'min:-9999999999', 'max:9999999999'],
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
            'month.required' => 'Не указан месяц.',
            'month.regex' => 'Месяц должен быть в формате ГГГГ-ММ.',
            'component.required' => 'Не указан тип строки.',
            'component.in' => 'Тип строки может быть только «Доп. доход» или «Корректировка».',
            'label.required' => 'Укажите название позиции.',
            'label.max' => 'Название не может быть длиннее 255 символов.',
            'qty.numeric' => 'Количество должно быть числом.',
            'qty.min' => 'Количество должно быть больше нуля.',
            'price.required' => 'Укажите сумму.',
            'price.numeric' => 'Сумма должна быть числом.',
            'comment.max' => 'Основание не может быть длиннее 255 символов.',
        ];
    }
}
