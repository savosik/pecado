<?php

namespace App\Http\Requests\Crm;

use App\Services\Crm\Mail\ConditionEvaluator;
use App\Services\Crm\Mail\MailFieldCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Проверка правила-фильтра.
 *
 * Все сообщения на русском: правило заводит менеджер, а не разработчик.
 */
class StoreMailRuleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'match' => ['required', Rule::in(['all', 'any'])],
            'conditions' => ['present', 'array', 'max:20'],
            'conditions.*.field' => ['required', 'string', Rule::in(MailFieldCatalog::allFields())],
            'conditions.*.op' => ['required', 'string', Rule::in(MailFieldCatalog::allOperators())],
            'conditions.*.value' => ['nullable'],
            'recipients' => ['required', 'array', 'min:1', 'max:30'],
            'recipients.*' => ['required', 'string', 'max:255'],
            'cc' => ['nullable', 'array', 'max:30'],
            'cc.*' => ['required', 'string', 'max:255'],
            // Пустой список — «все партнёры». Тысяча подписчиков в одном
            // правиле — уже признак, что нужно правило без адресной части.
            'client_ids' => ['nullable', 'array', 'max:1000'],
            'client_ids.*' => ['integer'],
            'auto_send' => ['boolean'],
            'is_active' => ['boolean'],
            'throttle_minutes' => ['nullable', 'integer', 'min:1', 'max:20160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Дайте правилу название — по нему его будут искать в списке.',
            'name.max' => 'Название длиннее 120 символов не поместится в список.',
            'match.in' => 'Выберите, должны совпасть все условия или достаточно любого.',
            'conditions.max' => 'Больше двадцати условий в одном правиле — верный признак, что правил нужно два.',
            'conditions.*.field.in' => 'Неизвестное поле письма.',
            'conditions.*.op.in' => 'Неизвестное сравнение.',
            'recipients.required' => 'Укажите хотя бы одного получателя.',
            'recipients.min' => 'Укажите хотя бы одного получателя.',
            'client_ids.max' => 'Тысяча подписчиков в одном правиле — проще снять отбор и оставить «все партнёры».',
            'throttle_minutes.min' => 'Минимальное ограничение — одна минута.',
            'throttle_minutes.max' => 'Ограничение больше двух недель бессмысленно.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('conditions', []) as $index => $condition) {
                $op = (string) ($condition['op'] ?? '');
                $value = $condition['value'] ?? null;

                if (in_array($op, MailFieldCatalog::unaryOperators(), true)) {
                    continue;
                }

                if (blank($value)) {
                    $validator->errors()->add(
                        "conditions.{$index}.value",
                        'Укажите значение — без него условие не сработает никогда.',
                    );

                    continue;
                }

                // Кривое выражение либо молчит, либо ловит всё, и заметить это
                // можно только по последствиям. Поэтому ошибка — здесь.
                if ($op === 'regex' && ! ConditionEvaluator::isValidRegex((string) $value)) {
                    $validator->errors()->add(
                        "conditions.{$index}.value",
                        'Выражение составлено с ошибкой и не может быть применено.',
                    );
                }
            }

            foreach (['recipients', 'cc'] as $field) {
                foreach ((array) $this->input($field, []) as $index => $address) {
                    if ($this->isValidRecipient((string) $address)) {
                        continue;
                    }

                    $validator->errors()->add(
                        "{$field}.{$index}",
                        'Это не похоже на адрес электронной почты.',
                    );
                }
            }
        });
    }

    /**
     * Спецзначения — не адреса, а указания, кого подставить: «клиент»,
     * «менеджер» и любая роль контакта («бухгалтер», «директор»). Без них
     * правило «бухгалтеру этого контрагента» пришлось бы заводить отдельно
     * на каждого из восьмисот партнёров.
     */
    private function isValidRecipient(string $address): bool
    {
        $address = mb_strtolower(trim($address));

        if (in_array($address, ['клиент', 'менеджер'], true)) {
            return true;
        }

        // Роль контакта: «бухгалтер» раскроется в адреса бухгалтеров партнёра.
        foreach (\App\Enums\ContactRole::cases() as $role) {
            if (mb_strtolower($role->label()) === $address || $role->value === $address) {
                return true;
            }
        }

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }
}
