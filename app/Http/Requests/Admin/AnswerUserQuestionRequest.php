<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AnswerUserQuestionRequest extends FormRequest
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
            'answer' => ['required', 'string', 'min:5', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answer.required' => 'Введите текст ответа.',
            'answer.min' => 'Ответ слишком короткий (минимум 5 символов).',
            'answer.max' => 'Ответ слишком длинный (максимум 10 000 символов).',
        ];
    }
}
