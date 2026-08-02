<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeClientLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm-profile.edit') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lifecycle_status' => ['required', Rule::enum(ClientLifecycleStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lifecycle_status.required' => 'Выберите жизненный статус.',
            'lifecycle_status.enum' => 'Такого жизненного статуса нет.',
            'reason.max' => 'Причина длиннее 255 символов — сократите или запишите подробности в комментарий.',
        ];
    }
}
