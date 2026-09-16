<?php

namespace App\Services\Company;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Rules\TaxId;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Юрлица клиента: правила реквизитов, «забрать осиротевшую / отказать чужой», умолчание.
 *
 * Одна реализация для кабинета (форма, диалог чекаута) и API v1. Компания с
 * ИНН, уже привязанная к другому аккаунту, — отказ с направлением к менеджеру;
 * осиротевшая (пришла из 1С без владельца) — забирается.
 */
class CompanyClaimService
{
    /**
     * Правила реквизитов. На редактировании ИНН не должен наехать на чужую
     * привязанную компанию; на создании ту же роль играет {@see claimOrCreate()}.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(?string $country, ?int $companyId = null, bool $legalNameRequired = false): array
    {
        $taxIdRules = ['required', 'string', 'max:255', new TaxId($country)];

        if ($companyId !== null) {
            $taxIdRules[] = Rule::unique('companies', 'tax_id')->whereNull('deleted_at')->ignore($companyId);
        }

        return [
            'country' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => [$legalNameRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'tax_id' => $taxIdRules,
            'registration_number' => ['nullable', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:255'],
            'okpo_code' => ['nullable', 'string', 'max:255'],
            'legal_address' => ['nullable', 'string'],
            'legal_address_data' => ['nullable', 'array'],
            'actual_address' => ['nullable', 'string'],
            'actual_address_data' => ['nullable', 'array'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.required' => 'Выберите страну.',
            'name.required' => 'Название обязательно.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'legal_name.required' => 'Юридическое название обязательно.',
            'tax_id.required' => 'ИНН обязателен.',
            'tax_id.unique' => 'Компания с таким ИНН уже зарегистрирована в системе.',
            'phone.regex' => 'Введите корректный номер телефона.',
            'email.email' => 'Введите корректный email.',
        ];
    }

    /**
     * Создать компанию либо забрать осиротевшую с тем же ИНН.
     *
     * @param  array<string, mixed>  $attributes  проверенные реквизиты без user_id
     *
     * @throws ValidationException ИНН привязан к другому аккаунту
     */
    public function claimOrCreate(User $user, array $attributes): Company
    {
        $attributes['user_id'] = $user->getKey();

        return DB::transaction(function () use ($attributes) {
            // Обходим CompanyScope (он ограничивает выборку текущим юзером),
            // чтобы увидеть и осиротевшие записи, и принадлежащие другим.
            $existing = Company::withoutGlobalScope(CompanyScope::class)
                ->where('tax_id', $attributes['tax_id'])
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return Company::create($attributes);
            }

            if ($existing->user_id !== null && $existing->user_id !== $attributes['user_id']) {
                throw ValidationException::withMessages([
                    'tax_id' => 'Этот ИНН уже привязан к другому аккаунту. Если это ваша компания — обратитесь к менеджеру.',
                ]);
            }

            $existing->fill($attributes);
            $existing->user_id = $attributes['user_id'];
            $existing->save();

            return $existing;
        });
    }

    /**
     * Сделать компанию основной (или снять признак). Основная — одна.
     */
    public function setDefault(User $user, Company $company, bool $value = true): void
    {
        Company::where('user_id', $user->getKey())->update(['is_default' => false]);

        if ($value) {
            $company->update(['is_default' => true]);
        }
    }
}
