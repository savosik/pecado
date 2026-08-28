<?php

namespace App\Http\Requests\Crm;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Разблокировка до даты. Потолок срока — по роли: менеджер ≤ 14 дней,
 * РОП (crm-clients-all.view) ≤ 30. Бессрочной нет ни у кого.
 */
class StoreDebtPauseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm-finance.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'until' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'until.required' => 'Укажите, до какой даты снять ограничение.',
            'until.after_or_equal' => 'Дата разблокировки не может быть в прошлом.',
            'reason.required' => 'Напишите причину: что обещал клиент.',
            'reason.min' => 'Причина слишком короткая — через месяц по ней нужно понять, о чём договорились.',
        ];
    }

    public function maxDays(): int
    {
        return $this->user()?->can('crm-clients-all.view')
            ? (int) config('debt.pause_max_days_head', 30)
            : (int) config('debt.pause_max_days_manager', 14);
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $until = $this->input('until');

                if (! is_string($until) || $until === '') {
                    return;
                }

                $limit = CarbonImmutable::today()->addDays($this->maxDays());

                if (CarbonImmutable::parse($until)->greaterThan($limit)) {
                    $validator->errors()->add('until', sprintf(
                        'Не больше %d дней от сегодня (до %s). Нужен срок длиннее — попросите руководителя отдела.',
                        $this->maxDays(),
                        $limit->format('d.m.Y'),
                    ));
                }
            },
        ];
    }
}
