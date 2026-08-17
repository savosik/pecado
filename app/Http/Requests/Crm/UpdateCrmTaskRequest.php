<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Services\Crm\CrmTaskService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Правка задачи. Доступ проверяет политика в контроллере — здесь только формат.
 *
 * Все поля `sometimes`: карточка правит задачу целиком, а кнопка «Выполнено»
 * в списке шлёт один статус, и она не должна быть обязана присылать заголовок.
 */
class UpdateCrmTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'min:2', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assignee_id' => ['sometimes', 'integer', 'exists:users,id', $this->crmAccessRule()],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'estimate_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4800'],
            'co_assignee_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'co_assignee_ids.*' => ['integer', 'exists:users,id', $this->crmAccessRule()],
        ];
    }

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
            'title.min' => 'Слишком короткий заголовок задачи.',
            'title.max' => 'Заголовок не может быть длиннее 255 символов.',
            'description.max' => 'Описание не может быть длиннее 5000 символов.',
            'assignee_id.exists' => 'Такого сотрудника нет.',
            'due_at.date' => 'Укажите корректную дату дедлайна.',
        ];
    }
}
