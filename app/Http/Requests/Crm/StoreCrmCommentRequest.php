<?php

namespace App\Http\Requests\Crm;

use App\Models\CrmComment;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CrmComment::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Тип проверяем по карте сущностей: произвольная строка из запроса
            // не должна превращаться в класс приложения.
            'entity_type' => ['required', 'string', Rule::in(CrmEntityMap::commentableTypes())],
            'entity_id' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entity_type.required' => 'Не указано, к чему относится комментарий.',
            'entity_type.in' => 'К этому типу записей комментарии не поддерживаются.',
            'entity_id.required' => 'Не указана запись, к которой относится комментарий.',
            'body.required' => 'Введите текст комментария.',
            'body.max' => 'Комментарий не может быть длиннее 5000 символов.',
        ];
    }
}
