<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreUserQuestionRequest extends FormRequest
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
            'email' => [Rule::requiredIf(! Auth::check()), 'nullable', 'email', 'max:191'],
            'name' => ['nullable', 'string', 'max:100'],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'file' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,txt',
            ],
            'website' => ['nullable', 'prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Укажите email для обратной связи.',
            'email.email' => 'Введите корректный email.',
            'email.max' => 'Email слишком длинный.',
            'name.max' => 'Имя слишком длинное (максимум 100 символов).',
            'subject.required' => 'Укажите тему вопроса.',
            'subject.min' => 'Тема слишком короткая (минимум 3 символа).',
            'subject.max' => 'Тема слишком длинная (максимум 200 символов).',
            'body.required' => 'Опишите ваш вопрос.',
            'body.min' => 'Вопрос слишком короткий (минимум 10 символов).',
            'body.max' => 'Вопрос слишком длинный (максимум 5000 символов).',
            'file.file' => 'Не удалось загрузить файл.',
            'file.max' => 'Файл слишком большой (максимум 10 МБ).',
            'file.mimes' => 'Допустимые форматы: jpg, png, webp, gif, pdf, doc, docx, xls, xlsx, txt.',
            'website.prohibited' => 'Подозрение на автоматическую отправку.',
        ];
    }
}
