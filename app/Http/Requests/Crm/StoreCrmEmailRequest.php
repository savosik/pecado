<?php

namespace App\Http\Requests\Crm;

use App\Models\CrmEmail;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CrmEmail::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'email'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['required', 'email'],
            'reply_to' => ['nullable', 'email'],
            'subject' => ['required', 'string', 'min:2', 'max:255'],
            'body_html' => ['required', 'string', 'max:100000'],
            'entity_type' => ['nullable', 'required_with:entity_id', 'string', Rule::in(CrmEntityMap::taskableTypes())],
            'entity_id' => ['nullable', 'required_with:entity_type', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.required' => 'Укажите хотя бы одного получателя.',
            'to.*.email' => 'Адрес получателя указан неверно.',
            'cc.*.email' => 'Адрес в копии указан неверно.',
            'reply_to.email' => 'Обратный адрес указан неверно.',
            'subject.required' => 'Введите тему письма.',
            'subject.max' => 'Тема не может быть длиннее 255 символов.',
            'body_html.required' => 'Письмо не может быть пустым.',
            'entity_type.in' => 'К этому типу записей письма не привязываются.',
            'entity_id.required_with' => 'Не указана запись, к которой привязать письмо.',
        ];
    }
}
