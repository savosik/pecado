<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Справочник наших организаций (юрлиц компании).
 *
 * Ведётся вручную, как склады: 1С справочник не присылает, в своих сообщениях она
 * указывает только UUID организации. `external_id` заполняет админ.
 */
class OrganizationController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = Organization::query();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('legal_name', 'like', '%'.$search.'%')
                    ->orWhere('tax_id', 'like', '%'.$search.'%')
                    ->orWhere('external_id', 'like', '%'.$search.'%');
            });
        }

        if ($request->filled('sort_by') && $request->filled('sort_order')) {
            $query->orderBy($request->string('sort_by')->toString(), $request->string('sort_order')->toString());
        } else {
            // Заглушки наверх: это единственный сигнал, что 1С работает
            // с незаведённым юрлицом.
            $query->ordered();
        }

        $organizations = $query->paginate($request->integer('per_page') ?: 10)
            ->withQueryString();

        return Inertia::render('Admin/Pages/Organizations/Index', [
            'organizations' => $organizations,
            'filters' => $request->only(['search', 'sort_by', 'sort_order']),
            'stubCount' => Organization::query()->stub()->count(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Organizations/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules($request), $this->messages());

        // Организацию, заведённую руками, заглушкой не считаем.
        $validated['is_stub'] = false;

        $organization = Organization::create($validated);

        return $this->redirectAfterSave(
            $request,
            'admin.organizations.index',
            'admin.organizations.edit',
            $organization,
            'Организация успешно создана',
        );
    }

    public function show(Organization $organization): \Inertia\Response
    {
        return Inertia::render('Admin/Pages/Organizations/Show', [
            'organization' => array_merge(
                $organization->only([
                    'id', 'external_id', 'name', 'legal_name', 'tax_id', 'tax_code',
                    'bank_name', 'bank_bik', 'correspondent_account', 'account_number',
                    'is_active', 'is_stub', 'sort_order',
                ]),
                [
                    'created_at' => $organization->created_at?->format('d.m.Y H:i'),
                    'updated_at' => $organization->updated_at?->format('d.m.Y H:i'),
                ],
            ),
        ]);
    }

    public function edit(Organization $organization)
    {
        return Inertia::render('Admin/Pages/Organizations/Edit', [
            'organization' => $organization,
        ]);
    }

    public function update(Request $request, Organization $organization)
    {
        $validated = $request->validate($this->rules($request, $organization), $this->messages());

        // Сохранение админом — подтверждение карточки: заглушка перестаёт быть заглушкой.
        // Именно здесь, а не при дозаполнении подсказкой из 1С: 1С не присылает
        // банковские реквизиты, а они нужны клиенту в разрезе взаиморасчётов.
        $validated['is_stub'] = false;

        $organization->update($validated);

        return $this->redirectAfterSave(
            $request,
            'admin.organizations.index',
            'admin.organizations.edit',
            $organization,
            'Организация успешно обновлена',
        );
    }

    /**
     * Мягкое удаление: за организацией стоят документы, физически удалять нельзя.
     */
    public function destroy(Organization $organization)
    {
        $organization->delete();

        return redirect()->route('admin.organizations.index')
            ->with('success', 'Организация успешно удалена');
    }

    /**
     * Поиск организаций для селектов (JSON).
     */
    public function search(Request $request)
    {
        $query = $request->input('query');

        $organizations = Organization::query()
            ->when($query, fn ($q) => $q->where('name', 'like', '%'.$query.'%'))
            ->ordered()
            ->take(20)
            ->get()
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'is_stub' => $organization->is_stub,
            ]);

        return response()->json($organizations);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?Organization $organization = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'external_id' => [
                'nullable',
                'string',
                'max:255',
                // Мягко удалённые учитываем: OrganizationResolver их восстанавливает,
                // а unique-индекс в БД про soft delete не знает.
                Rule::unique('organizations', 'external_id')->ignore($organization?->id),
            ],
            'legal_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:20',
            'tax_code' => 'nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
            'bank_bik' => 'nullable|string|max:20',
            'correspondent_account' => 'nullable|string|max:34',
            'account_number' => 'nullable|string|max:34',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'Укажите название организации.',
            'external_id.unique' => 'Организация с таким UUID из 1С уже заведена.',
        ];
    }
}
