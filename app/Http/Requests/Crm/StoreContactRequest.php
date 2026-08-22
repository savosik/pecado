<?php

namespace App\Http\Requests\Crm;

use App\Enums\ContactRole;
use App\Enums\Crm\PreferredChannel;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Проверка карточки человека.
 *
 * Все сообщения на русском: карточку заводит менеджер, а не разработчик.
 */
class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:191'],
            'greeting_name' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_extra' => ['nullable', 'string', 'max:50'],
            'telegram' => ['nullable', 'string', 'max:100'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'instagram' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:191'],
            'preferred_channel' => ['nullable', Rule::enum(PreferredChannel::class)],
            'birthday' => ['nullable', 'date'],
            'birthday_has_year' => ['boolean'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'marketing_consent' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Привязка сразу при создании — самый частый путь: человека заводят
            // из карточки контрагента, а не из пустого справочника.
            'entity_type' => ['nullable', 'string', Rule::in(\App\Support\Crm\CrmEntityMap::contactLinkableTypes())],
            'entity_id' => ['nullable', 'integer', 'min:1', 'required_with:entity_type'],
            'role' => ['nullable', Rule::enum(ContactRole::class), 'required_with:entity_type'],
            'role_note' => ['nullable', 'string', 'max:191'],
            'is_primary' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Укажите ФИО — по нему человека и будут искать.',
            'full_name.max' => 'ФИО длиннее 191 символа не поместится.',
            'email.email' => 'Это не похоже на адрес электронной почты.',
            'birthday.date' => 'Дата рождения указана неверно.',
            'entity_id.required_with' => 'Укажите, к кому привязать контакт.',
            'role.required_with' => 'Выберите роль: кем человек приходится этой карточке.',
            'notes.max' => 'Заметка длиннее 2000 символов не поместится.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Человек без единого способа связи бесполезен: ни позвонить,
            // ни написать, ни выгрузить в телефон.
            if (blank($this->input('email')) && blank($this->input('phone')) && blank($this->input('telegram'))) {
                $validator->errors()->add(
                    'phone',
                    'Укажите хотя бы один способ связи: телефон, почту или Telegram.',
                );
            }

            $this->checkDuplicate($validator);
        });
    }

    /**
     * Дубль в рамках одного партнёра.
     *
     * Проверка живёт здесь, а не уникальным индексом: мягкое удаление плюс
     * допустимость пустой почты сделали бы индекс бесполезным.
     */
    private function checkDuplicate(Validator $validator): void
    {
        $clientId = $this->input('client_id');

        if (blank($clientId)) {
            return;
        }

        $ignoreId = $this->route('contact')?->getKey();

        if (filled($this->input('email'))) {
            $exists = Contact::query()
                ->where('client_user_id', $clientId)
                ->where('email', mb_strtolower(trim((string) $this->input('email'))))
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($exists) {
                $validator->errors()->add('email', 'У этого партнёра уже есть контакт с такой почтой.');
            }
        }

        $digits = Contact::digitsOf($this->input('phone'));

        if ($digits !== null) {
            $exists = Contact::query()
                ->where('client_user_id', $clientId)
                ->where('phone_digits', $digits)
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($exists) {
                $validator->errors()->add('phone', 'У этого партнёра уже есть контакт с таким телефоном.');
            }
        }
    }
}
