<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Правка черновика. Доступ проверяет политика в контроллере — здесь только формат.
 */
class UpdateCrmEmailRequest extends FormRequest
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
            'tracking_enabled' => ['boolean'],
            'to' => ['sometimes', 'array', 'min:1'],
            'to.*' => ['required', 'email'],
            'cc' => ['sometimes', 'nullable', 'array'],
            'cc.*' => ['required', 'email'],
            'reply_to' => ['sometimes', 'nullable', 'email'],
            'subject' => ['sometimes', 'string', 'min:2', 'max:255'],
            'body_html' => ['sometimes', 'string', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.min' => 'Укажите хотя бы одного получателя.',
            'to.*.email' => 'Адрес получателя указан неверно.',
            'cc.*.email' => 'Адрес в копии указан неверно.',
            'reply_to.email' => 'Обратный адрес указан неверно.',
            'subject.max' => 'Тема не может быть длиннее 255 символов.',
        ];
    }
}
