<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\CallDirection;
use App\Enums\Crm\CallResult;
use App\Enums\Crm\TaskPriority;
use App\Models\CrmCall;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CrmCall::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'direction' => ['sometimes', Rule::enum(CallDirection::class)],
            'result' => ['sometimes', Rule::enum(CallResult::class)],
            'phone' => ['nullable', 'string', 'max:32'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            // Итог разговора необязателен: «не ответил» описывать нечем, а требовать
            // текст на каждую попытку — верный способ отучить фиксировать звонки.
            'summary' => ['nullable', 'string', 'max:5000'],
            'started_at' => ['nullable', 'date'],
            'duration_sec' => ['nullable', 'integer', 'min:0', 'max:86400'],

            // Половина пары бессмысленна — тип без ID и наоборот. Звонок без привязки
            // допустим: «набрал по визитке» живёт в журнале сам по себе.
            'entity_type' => ['nullable', 'required_with:entity_id', 'string', Rule::in(CrmEntityMap::taskableTypes())],
            'entity_id' => ['nullable', 'required_with:entity_type', 'integer', 'min:1'],

            'follow_up' => ['nullable', 'array'],
            'follow_up.title' => ['required_with:follow_up', 'string', 'min:2', 'max:255'],
            'follow_up.description' => ['nullable', 'string', 'max:5000'],
            'follow_up.due_at' => ['nullable', 'date'],
            'follow_up.assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'follow_up.priority' => ['nullable', Rule::enum(TaskPriority::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'direction.Illuminate\Validation\Rules\Enum' => 'Неизвестное направление звонка.',
            'result.Illuminate\Validation\Rules\Enum' => 'Неизвестный итог звонка.',
            'phone.max' => 'Номер не может быть длиннее 32 символов.',
            'summary.max' => 'Итог разговора не может быть длиннее 5000 символов.',
            'started_at.date' => 'Укажите корректное время разговора.',
            'duration_sec.integer' => 'Длительность указывается в секундах.',
            'duration_sec.max' => 'Длительность не может превышать сутки.',
            'entity_type.required_with' => 'Не указано, к чему привязать звонок.',
            'entity_type.in' => 'К этому типу записей звонки не привязываются.',
            'entity_id.required_with' => 'Не указана запись, к которой привязать звонок.',
            'follow_up.title.required_with' => 'Введите, что нужно сделать по итогам звонка.',
            'follow_up.title.min' => 'Слишком короткий заголовок задачи.',
            'follow_up.due_at.date' => 'Укажите корректный срок следующего шага.',
        ];
    }
}
