<?php

namespace App\Http\Controllers\User;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Rules\TaxId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::withCount('bankAccounts')
            ->with(['contractorBalance.overdueDetails'])
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('User/Cabinet/Companies/Index', [
            'companies' => $companies,
        ]);
    }

    public function create()
    {
        return Inertia::render('User/Cabinet/Companies/Form', [
            'company' => null,
            'countries' => collect(Country::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateCompany($request);
        $validated['user_id'] = Auth::id();

        $company = $this->claimOrCreateCompany($validated);

        return redirect()->route('cabinet.companies.edit', $company)
            ->with('success', 'Компания успешно создана.');
    }

    public function edit(Company $company)
    {
        $this->authorizeCompany($company);
        $company->load('bankAccounts');

        return Inertia::render('User/Cabinet/Companies/Form', [
            'company' => $company,
            'countries' => collect(Country::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $validated = $this->validateCompany($request, $company->id);
        $company->update($validated);

        return back()->with('success', 'Компания успешно обновлена.');
    }

    public function destroy(Request $request, Company $company)
    {
        $this->authorizeCompany($company);
        $company->delete();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('cabinet.companies.index')
            ->with('success', 'Компания успешно удалена.');
    }

    /**
     * JSON API для создания компании (например, из диалога на странице Checkout).
     * POST /cabinet/companies/api
     */
    public function apiStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['required', 'string', 'max:255'],
            'tax_id' => [
                'required',
                'string',
                'max:255',
                new TaxId($request->input('country')),
            ],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:255'],
            'okpo_code' => ['nullable', 'string', 'max:255'],
            'legal_address' => ['nullable', 'string'],
            'legal_address_data' => ['nullable', 'array'],
            'actual_address' => ['nullable', 'string'],
            'actual_address_data' => ['nullable', 'array'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
        ], [
            'country.required' => 'Выберите страну.',
            'name.required' => 'Название обязательно.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'legal_name.required' => 'Юридическое название обязательно.',
            'tax_id.required' => 'ИНН обязателен.',
            'phone.regex' => 'Введите корректный номер телефона.',
            'email.email' => 'Введите корректный email.',
        ]);

        $validated['user_id'] = Auth::id();

        $company = $this->claimOrCreateCompany($validated);

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
            ],
        ], 201);
    }

    private function authorizeCompany(Company $company): void
    {
        abort_if($company->user_id !== Auth::id(), 403, 'Доступ запрещён.');
    }

    private function validateCompany(Request $request, ?int $companyId = null): array
    {
        $taxIdRules = [
            'required',
            'string',
            'max:255',
            new TaxId($request->input('country')),
        ];

        // На редактировании tax_id меняется редко, но если меняют — должна быть проверка
        // от наезда на чужую привязанную компанию. На создании ту же роль выполняет
        // claimOrCreateCompany() — там логика «забрать осиротевшую / отказать чужой».
        if ($companyId !== null) {
            $taxIdRules[] = Rule::unique('companies', 'tax_id')
                ->whereNull('deleted_at')
                ->ignore($companyId);
        }

        return $request->validate([
            'country' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
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
        ], [
            'country.required' => 'Выберите страну.',
            'name.required' => 'Название обязательно.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'tax_id.required' => 'ИНН обязателен.',
            'tax_id.unique' => 'Компания с таким ИНН уже зарегистрирована в системе.',
            'phone.regex' => 'Введите корректный номер телефона.',
            'email.email' => 'Введите корректный email.',
        ]);
    }

    /**
     * Создать компанию или «забрать» уже существующую.
     *
     * Из 1С/админки в `companies` могут попадать «осиротевшие» записи
     * (`user_id IS NULL`). При попытке регистрации с таким же ИНН раньше
     * валидатор просто отвечал «уже зарегистрирована», и юзер не мог
     * привязать свою компанию. Теперь:
     *
     *  - запись с этим ИНН не существует → создаём;
     *  - есть и `user_id IS NULL` → привязываем к текущему юзеру, обновляем поля;
     *  - есть и принадлежит текущему юзеру → обновляем (idempotent);
     *  - есть и привязана к другому → ValidationException с понятным текстом.
     */
    private function claimOrCreateCompany(array $validated): Company
    {
        return DB::transaction(function () use ($validated) {
            // Обходим CompanyScope (он ограничивает выборку текущим юзером),
            // чтобы увидеть и осиротевшие записи, и принадлежащие другим.
            $existing = Company::withoutGlobalScope(CompanyScope::class)
                ->where('tax_id', $validated['tax_id'])
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return Company::create($validated);
            }

            if ($existing->user_id !== null && $existing->user_id !== $validated['user_id']) {
                throw ValidationException::withMessages([
                    'tax_id' => 'Этот ИНН уже привязан к другому аккаунту. Если это ваша компания — обратитесь к менеджеру.',
                ]);
            }

            $existing->fill($validated);
            $existing->user_id = $validated['user_id'];
            $existing->save();

            return $existing;
        });
    }
}
