<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCrmCommentRequest extends FormRequest
{
    /**
     * Права проверяет контроллер через политику: ему доступен сам комментарий,
     * а решение зависит от автора и от видимости клиента.
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
            'body' => ['sometimes', 'required', 'string', 'min:1', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Введите текст комментария.',
            'body.max' => 'Комментарий не может быть длиннее 5000 символов.',
        ];
    }
}
