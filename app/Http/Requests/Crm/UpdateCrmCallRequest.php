<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\CallDirection;
use App\Enums\Crm\CallResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrmCallRequest extends FormRequest
{
    /**
     * Доступ проверяет политика в контроллере: сюда звонок приходит уже
     * разрезолвленным, и дублировать проверку значило бы завести вторую.
     */
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
            'direction' => ['sometimes', Rule::enum(CallDirection::class)],
            'result' => ['sometimes', Rule::enum(CallResult::class)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'started_at' => ['sometimes', 'nullable', 'date'],
            'duration_sec' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'summary.max' => 'Итог разговора не может быть длиннее 5000 символов.',
            'started_at.date' => 'Укажите корректное время разговора.',
            'duration_sec.max' => 'Длительность не может превышать сутки.',
        ];
    }
}
