<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\ClientSentiment;
use App\Enums\Crm\PaymentBehavior;
use App\Enums\Crm\PreferredChannel;
use App\Support\Crm\ClientPassport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientProfileRequest extends FormRequest
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
        // Правила паспорта берутся из ClientPassport: перечень полей один
        // на форму, агентское API и базу — расходиться нечему.
        return ClientPassport::rules() + [
            'decision_maker_name' => ['nullable', 'string', 'max:255'],
            'decision_maker_role' => ['nullable', 'string', 'max:255'],
            'decision_maker_contact' => ['nullable', 'string', 'max:255'],
            'decision_process' => ['nullable', 'string', 'max:5000'],

            'payment_behavior' => ['nullable', Rule::enum(PaymentBehavior::class)],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'order_cycle_days' => ['nullable', 'integer', 'min:1', 'max:1095'],

            'preferred_channel' => ['nullable', Rule::enum(PreferredChannel::class)],
            'sentiment' => ['nullable', Rule::enum(ClientSentiment::class)],

            'notes_md' => ['nullable', 'string', 'max:65535'],

            'interests' => ['nullable', 'array', 'max:30'],
            'interests.*' => ['string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ClientPassport::messages() + [
            'decision_maker_name.max' => 'Имя ЛПР длиннее 255 символов.',
            'decision_maker_role.max' => 'Должность ЛПР длиннее 255 символов.',
            'decision_maker_contact.max' => 'Контакт ЛПР длиннее 255 символов.',
            'decision_process.max' => 'Описание процесса принятия решения длиннее 5000 символов.',
            'payment_behavior.enum' => 'Выберите платёжное поведение из списка.',
            'payment_terms.max' => 'Условия оплаты длиннее 255 символов.',
            'order_cycle_days.integer' => 'Периодичность закупок указывается целым числом дней.',
            'order_cycle_days.min' => 'Периодичность закупок не может быть меньше одного дня.',
            'order_cycle_days.max' => 'Периодичность закупок не может быть больше трёх лет.',
            'preferred_channel.enum' => 'Выберите канал связи из списка.',
            'sentiment.enum' => 'Выберите настроение из списка.',
            'notes_md.max' => 'Заметки слишком длинные — сократите или вынесите часть во вложение.',
            'interests.max' => 'Не больше 30 интересов на клиента.',
            'interests.*.max' => 'Название интереса длиннее 50 символов.',
        ];
    }

    /**
     * Пустые строки из формы — это «не заполнено», а не пустое значение поля.
     * Без этого селект, очищенный менеджером, упал бы на проверке енума.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        $fields = array_merge(
            ['payment_behavior', 'preferred_channel', 'sentiment', 'order_cycle_days'],
            ClientPassport::nullableOnEmpty(),
        );

        foreach ($fields as $field) {
            if ($this->input($field) === '') {
                $normalized[$field] = null;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
