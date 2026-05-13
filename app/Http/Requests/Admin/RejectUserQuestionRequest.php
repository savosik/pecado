<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RejectUserQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('user-questions.edit') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rejected_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rejected_reason.max' => 'Причина слишком длинная (максимум 500 символов).',
        ];
    }
}
