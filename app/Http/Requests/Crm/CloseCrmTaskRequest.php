<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\TaskPriority;
use App\Services\Crm\CrmTaskService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Закрытие задачи: отметка о выполнении, необязательный комментарий
 * и необязательный следующий шаг.
 *
 * Всё необязательно, кроме заголовка follow-up — если следующий шаг заводят,
 * он должен быть сформулирован, иначе в списке появится безымянная задача.
 */
class CloseCrmTaskRequest extends FormRequest
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
            'comment' => ['nullable', 'string', 'max:5000'],
            'follow_up' => ['nullable', 'array'],
            'follow_up.title' => ['required_with:follow_up', 'string', 'min:2', 'max:255'],
            'follow_up.description' => ['nullable', 'string', 'max:5000'],
            'follow_up.assignee_id' => ['nullable', 'integer', 'exists:users,id', $this->crmAccessRule()],
            'follow_up.priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'follow_up.due_at' => ['nullable', 'date'],
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
            'comment.max' => 'Комментарий не может быть длиннее 5000 символов.',
            'follow_up.title.required_with' => 'Сформулируйте следующий шаг или снимите галочку.',
            'follow_up.title.min' => 'Слишком короткий заголовок следующей задачи.',
            'follow_up.title.max' => 'Заголовок не может быть длиннее 255 символов.',
            'follow_up.due_at.date' => 'Укажите корректную дату дедлайна.',
            'follow_up.assignee_id.exists' => 'Такого сотрудника нет.',
        ];
    }
}
