<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class AssignClientManagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm-clients-all.edit') ?? false;
    }

    /**
     * `personal_manager_id = null` — снять закрепление: партнёр остаётся в базе
     * отдела без менеджера (лид). Активность карточки проверяет контроллер.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'personal_manager_id' => ['nullable', 'integer', 'exists:personal_managers,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'personal_manager_id.integer' => 'Выберите менеджера из списка.',
            'personal_manager_id.exists' => 'Такой карточки менеджера нет.',
            'reason.max' => 'Причина длиннее 255 символов — сократите или запишите подробности в комментарий.',
        ];
    }
}
