<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Services\Crm\CrmTaskService;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CrmTask::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => ['required', 'integer', 'exists:users,id', $this->crmAccessRule()],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'due_at' => ['nullable', 'date'],
            // Привязки может не быть вовсе: «позвонить в пятницу» живёт в списке само по себе.
            // Но половина пары бессмысленна — тип без ID и наоборот.
            'entity_type' => ['nullable', 'required_with:entity_id', 'string', Rule::in(CrmEntityMap::taskableTypes())],
            'entity_id' => ['nullable', 'required_with:entity_type', 'integer', 'min:1'],
        ];
    }

    /**
     * Исполнителем можно назначить только сотрудника с доступом в CRM.
     *
     * Задача, поручённая кладовщику или клиенту, не появилась бы ни в одном
     * интерфейсе — то есть молча потерялась бы.
     */
    protected function crmAccessRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! app(CrmTaskService::class)->canBeAssigned((int) $value)) {
                $fail('Исполнителем можно назначить только сотрудника с доступом в CRM.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Введите, что нужно сделать.',
            'title.min' => 'Слишком короткий заголовок задачи.',
            'title.max' => 'Заголовок не может быть длиннее 255 символов.',
            'description.max' => 'Описание не может быть длиннее 5000 символов.',
            'assignee_id.required' => 'Выберите исполнителя.',
            'assignee_id.exists' => 'Такого сотрудника нет.',
            'due_at.date' => 'Укажите корректную дату дедлайна.',
            'entity_type.required_with' => 'Не указано, к чему привязать задачу.',
            'entity_type.in' => 'К этому типу записей задачи не привязываются.',
            'entity_id.required_with' => 'Не указана запись, к которой привязать задачу.',
        ];
    }
}
