<?php

namespace App\Services\Client\Api\Operations;

use App\Enums\Country;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Company\CompanyClaimService;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Support\Facades\Validator;

/**
 * Юрлица клиента и их банковские счета. Удаления нет — только смена основной.
 */
class CompanyOperations implements OperationProvider
{
    public function __construct(private readonly CompanyClaimService $companies) {}

    public static function section(): array
    {
        return ['companies', 'Юрлица'];
    }

    /**
     * @return list<Param>
     */
    private static function companyParams(bool $create): array
    {
        return [
            Param::string('country', 'Страна', $create, enum: array_column(Country::cases(), 'value')),
            Param::string('name', 'Название', $create, ['max:255']),
            Param::string('legal_name', 'Юридическое название', rules: ['max:255'], nullable: true),
            Param::string('tax_id', 'ИНН', $create, ['max:255']),
            Param::string('registration_number', 'ОГРН / регистрационный номер', rules: ['max:255'], nullable: true),
            Param::string('tax_code', 'КПП', rules: ['max:255'], nullable: true),
            Param::string('okpo_code', 'ОКПО', rules: ['max:255'], nullable: true),
            Param::string('legal_address', 'Юридический адрес', nullable: true),
            Param::string('actual_address', 'Фактический адрес', nullable: true),
            Param::string('phone', 'Телефон в формате +79991234567', rules: ['max:20'], nullable: true),
            Param::string('email', 'E-mail', rules: ['max:255'], nullable: true),
        ];
    }

    public static function operations(): array
    {
        $company = Param::integer('company', 'id юрлица', true);
        $account = [
            Param::string('bank_name', 'Банк', true, ['max:255']),
            Param::string('bank_bik', 'БИК', rules: ['max:20'], nullable: true),
            Param::string('correspondent_account', 'Корреспондентский счёт', rules: ['max:30'], nullable: true),
            Param::string('account_number', 'Расчётный счёт', true, ['max:30']),
            Param::boolean('is_primary', 'Основной счёт'),
        ];

        return [
            new Operation(
                id: 'companies.list', section: 'companies', method: 'GET', uri: 'companies',
                summary: 'Мои юрлица с банковскими счетами',
                description: 'is_default — основная компания, которая подставляется в заказы без company_id.',
                params: [],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'companies.create', section: 'companies', method: 'POST', uri: 'companies',
                summary: 'Добавить юрлицо',
                description: 'ИНН проверяется по стране. Компания с этим ИНН, уже привязанная к другому аккаунту, — отказ '
                    .'(обратитесь к менеджеру); пришедшая из 1С без владельца — забирается. Принимает Idempotency-Key.',
                params: self::companyParams(true),
                handler: [self::class, 'create'],
                mutating: true, idempotent: true,
            ),
            new Operation(
                id: 'companies.get', section: 'companies', method: 'GET', uri: 'companies/{company}',
                summary: 'Карточка юрлица',
                description: 'Реквизиты и банковские счета.',
                params: [$company],
                handler: [self::class, 'get'],
            ),
            new Operation(
                id: 'companies.update', section: 'companies', method: 'PATCH', uri: 'companies/{company}',
                summary: 'Изменить реквизиты юрлица',
                description: 'Передаются только меняемые поля; страна нужна, если меняется ИНН.',
                params: [$company, ...self::companyParams(false)],
                handler: [self::class, 'update'],
                mutating: true,
            ),
            new Operation(
                id: 'companies.set-default', section: 'companies', method: 'POST', uri: 'companies/{company}/default',
                summary: 'Сделать юрлицо основным',
                description: 'Основная компания — одна; прежняя перестаёт быть основной.',
                params: [$company],
                handler: [self::class, 'setDefault'],
                mutating: true,
            ),
            new Operation(
                id: 'bank-accounts.create', section: 'companies', method: 'POST', uri: 'companies/{company}/bank-accounts',
                summary: 'Добавить банковский счёт юрлицу',
                description: 'is_primary снимает признак с прежнего основного счёта.',
                params: [$company, ...$account],
                handler: [self::class, 'createAccount'],
                mutating: true, idempotent: true,
            ),
            new Operation(
                id: 'bank-accounts.update', section: 'companies', method: 'PATCH', uri: 'bank-accounts/{account}',
                summary: 'Изменить банковский счёт',
                description: 'Счёт должен принадлежать юрлицу клиента.',
                params: [Param::integer('account', 'id счёта', true), ...$account],
                handler: [self::class, 'updateAccount'],
                mutating: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        $companies = $actor->companies()->with('bankAccounts')->orderByDesc('is_default')->orderBy('id')->get();

        return Envelope::data($companies->map(fn (Company $c) => $this->row($c))->values()->all());
    }

    /** @return array<string, mixed> */
    public function get(User $actor, OperationInput $input): array
    {
        return Envelope::data($this->row($this->company($actor, $input)->load('bankAccounts')));
    }

    /** @return array<string, mixed> */
    public function create(User $actor, OperationInput $input): array
    {
        $data = $input->only(array_map(fn (Param $p) => $p->name, self::companyParams(true)));
        $validated = Validator::make($data, $this->companies->rules($data['country'] ?? null), $this->companies->messages())->validate();

        $company = $this->companies->claimOrCreate($actor, $validated);

        if ($actor->companies()->count() === 1) {
            $this->companies->setDefault($actor, $company);
        }

        return Envelope::data($this->row($company->fresh('bankAccounts')), ['created' => true]);
    }

    /** @return array<string, mixed> */
    public function update(User $actor, OperationInput $input): array
    {
        $company = $this->company($actor, $input);
        $changes = $input->only(array_map(fn (Param $p) => $p->name, self::companyParams(false)));

        $merged = array_merge($company->only(array_keys($this->companies->rules(null))), $changes);
        $merged['country'] = $merged['country'] instanceof Country ? $merged['country']->value : $merged['country'];

        $validated = Validator::make($merged, $this->companies->rules($merged['country'] ?? null, (int) $company->id), $this->companies->messages())->validate();
        $company->update($validated);

        return Envelope::data($this->row($company->fresh('bankAccounts')));
    }

    /** @return array<string, mixed> */
    public function setDefault(User $actor, OperationInput $input): array
    {
        $company = $this->company($actor, $input);
        $this->companies->setDefault($actor, $company);

        return Envelope::data($this->row($company->fresh('bankAccounts')));
    }

    /** @return array<string, mixed> */
    public function createAccount(User $actor, OperationInput $input): array
    {
        $company = $this->company($actor, $input);
        $data = $input->only(['bank_name', 'bank_bik', 'correspondent_account', 'account_number', 'is_primary']);

        if (! empty($data['is_primary'])) {
            CompanyBankAccount::where('company_id', $company->id)->update(['is_primary' => false]);
        }

        $account = $company->bankAccounts()->create($data + ['is_primary' => (bool) ($data['is_primary'] ?? false)]);

        return Envelope::data($this->account($account), ['created' => true]);
    }

    /** @return array<string, mixed> */
    public function updateAccount(User $actor, OperationInput $input): array
    {
        $account = CompanyBankAccount::query()
            ->whereKey((int) $input->int('account'))
            ->whereIn('company_id', $actor->companies()->select('id'))
            ->firstOrFail();

        $data = $input->only(['bank_name', 'bank_bik', 'correspondent_account', 'account_number', 'is_primary']);

        if (! empty($data['is_primary'])) {
            CompanyBankAccount::where('company_id', $account->company_id)->where('id', '!=', $account->id)->update(['is_primary' => false]);
        }

        $account->update($data);

        return Envelope::data($this->account($account->refresh()));
    }

    private function company(User $actor, OperationInput $input): Company
    {
        return $actor->companies()->whereKey((int) $input->int('company'))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function row(Company $company): array
    {
        return [
            'id' => (int) $company->id,
            'country' => $company->country?->value,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'inn' => $company->tax_id,
            'kpp' => $company->tax_code,
            'registration_number' => $company->registration_number,
            'okpo_code' => $company->okpo_code,
            'legal_address' => $company->legal_address,
            'actual_address' => $company->actual_address,
            'phone' => $company->phone,
            'email' => $company->email,
            'is_default' => (bool) $company->is_default,
            'bank_accounts' => $company->relationLoaded('bankAccounts')
                ? $company->bankAccounts->map(fn (CompanyBankAccount $a) => $this->account($a))->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function account(CompanyBankAccount $account): array
    {
        return [
            'id' => (int) $account->id,
            'company_id' => (int) $account->company_id,
            'bank_name' => $account->bank_name,
            'bank_bik' => $account->bank_bik,
            'correspondent_account' => $account->correspondent_account,
            'account_number' => $account->account_number,
            'is_primary' => (bool) $account->is_primary,
        ];
    }
}
