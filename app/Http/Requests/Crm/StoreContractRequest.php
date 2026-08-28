<?php

namespace App\Http\Requests\Crm;

use App\Enums\Crm\ContractForm;
use App\Enums\Crm\ContractPaymentTerms;
use App\Enums\Crm\ContractStatus;
use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Договор: создание и правка — поля одни, правила одни.
 *
 * Контрагент и партнёр проверяются на существование здесь, а на видимость
 * актору — в контроллере: чужой id не должен ни пройти, ни выдать 403.
 */
class StoreContractRequest extends FormRequest
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
            'category_id' => ['required', 'integer', 'exists:contract_categories,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'number' => ['required', 'string', 'max:100'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'client_id' => ['nullable', 'integer', 'exists:users,id'],
            'counterparty_name' => ['nullable', 'string', 'max:255', 'required_without:company_id'],
            'date' => ['nullable', 'date'],
            'signed_at' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', Rule::enum(ContractStatus::class)],
            'payment_terms' => ['nullable', Rule::enum(ContractPaymentTerms::class)],
            'form' => ['nullable', Rule::enum(ContractForm::class)],
            'responsible_manager_id' => ['nullable', 'integer', 'exists:personal_managers,id'],
            'is_visible_in_cabinet' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => 'Выберите категорию (вкладку) реестра.',
            'category_id.exists' => 'Такой категории нет.',
            'organization_id.exists' => 'Такой организации нет в справочнике.',
            'number.required' => 'Укажите номер договора.',
            'number.max' => 'Номер договора не длиннее 100 символов.',
            'company_id.exists' => 'Контрагент не найден.',
            'client_id.exists' => 'Партнёр не найден.',
            'counterparty_name.required_without' => 'Выберите контрагента из базы или впишите название стороны.',
            'counterparty_name.max' => 'Название стороны не длиннее 255 символов.',
            'date.date' => 'Дата договора указана неверно.',
            'signed_at.date' => 'Дата подписания указана неверно.',
            'valid_from.date' => 'Дата начала действия указана неверно.',
            'valid_until.date' => 'Дата окончания действия указана неверно.',
            'valid_until.after_or_equal' => 'Окончание действия не может быть раньше начала.',
            'status.required' => 'Укажите статус подписания.',
            'status.Illuminate\Validation\Rules\Enum' => 'Неизвестный статус подписания.',
            'payment_terms.Illuminate\Validation\Rules\Enum' => 'Неизвестный вариант оплаты.',
            'form.Illuminate\Validation\Rules\Enum' => 'Неизвестная форма договора.',
            'responsible_manager_id.exists' => 'Менеджер не найден.',
            'comment.max' => 'Комментарий не длиннее 5000 символов.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'категория',
            'number' => 'номер договора',
            'company_id' => 'контрагент',
            'client_id' => 'партнёр',
            'counterparty_name' => 'название стороны',
            'date' => 'дата договора',
            'signed_at' => 'дата подписания',
            'valid_from' => 'начало действия',
            'valid_until' => 'окончание действия',
            'status' => 'статус',
            'payment_terms' => 'вариант оплаты',
            'form' => 'форма',
            'responsible_manager_id' => 'ответственный',
            'comment' => 'комментарий',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Дубликат ищется, даже если в других полях ошибки: менеджер должен
            // увидеть все замечания разом, а не по одному за сохранение.
            if ($validator->errors()->hasAny(['category_id', 'number'])) {
                return;
            }

            // Один номер в одной категории — один договор. Проверка в PHP, а не
            // уникальным индексом: мягко удалённый договор не должен блокировать номер,
            // а «№ 1/2025» у ООО и у ИП — разные документы.
            $ignoreId = $this->route('contract')?->getKey();

            $duplicate = Contract::query()
                ->where('category_id', (int) $this->input('category_id'))
                ->where('number', trim((string) $this->input('number')))
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('number', 'Договор с таким номером в этой категории уже есть.');
            }

            // Подписанный без даты подписания — недосмотр, который потом не восстановить.
            if ($this->input('status') === ContractStatus::SIGNED->value && blank($this->input('signed_at'))) {
                $validator->errors()->add('signed_at', 'У подписанного договора укажите дату подписания.');
            }
        });
    }
}
