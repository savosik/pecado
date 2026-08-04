<?php

namespace App\Http\Requests\Crm;

use App\Enums\UserKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeClientKindRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm-clients-all.edit') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_kind' => ['required', Rule::enum(UserKind::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_kind.required' => 'Выберите тип аккаунта.',
            'user_kind.enum' => 'Такого типа аккаунта нет.',
            'reason.max' => 'Причина длиннее 255 символов — сократите или запишите подробности в комментарий.',
        ];
    }
}
