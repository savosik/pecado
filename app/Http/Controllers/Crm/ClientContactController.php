<?php

namespace App\Http\Controllers\Crm;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\User;
use App\Services\Notifications\ClientContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Адресная книга контрагентов — контактные лица партнёров.
 *
 * Отвечает на вопрос «кому именно писать по этому контрагенту»: бухгалтеру,
 * закупщику, директору. Эти карточки становятся адресатами правил пульта
 * уведомлений, поэтому адрес здесь валидируется, а не хранится текстом,
 * как это было в полях профиля CRM.
 *
 * Границу видимости задаёт видимость партнёра: кто видит партнёра, тот видит
 * его контакты. Чужой контакт отвечает 404, а не 403 — 403 подтвердил бы,
 * что такая запись существует.
 */
class ClientContactController extends CrmController
{
    public function __construct(private readonly ClientContactService $contacts) {}

    /**
     * Контакты партнёра (и опционально конкретного юрлица) — для вкладки карточки.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'company_id' => ['nullable', 'integer'],
        ], [], [
            'user_id' => 'партнёр',
            'company_id' => 'контрагент',
        ]);

        $user = User::query()->visibleInCrm($actor)->findOrFail($data['user_id']);

        return response()->json([
            'data' => $this->contacts
                ->forCompany($user->id, $data['company_id'] ?? null)
                ->map(fn (ClientContact $contact) => $this->payload($contact))
                ->all(),
            'roles' => ClientContactRole::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->crmActor($request);
        $data = $this->validated($request, $actor);

        ClientContact::create($data + [
            'source' => ClientContact::SOURCE_MANUAL,
            'created_by_user_id' => $actor->id,
        ]);

        return back()->with('success', 'Контакт добавлен');
    }

    public function update(Request $request, int $contact): RedirectResponse
    {
        $actor = $this->crmActor($request);

        $model = ClientContact::query()->visibleInCrm($actor)->findOrFail($contact);
        $data = $this->validated($request, $actor, $model);

        // Согласие на рассылки — юридически значимая отметка: фиксируем момент,
        // когда его поставили, и не трогаем дату, если значение не менялось.
        if (($data['marketing_consent'] ?? false) && ! $model->marketing_consent) {
            $data['marketing_consent_at'] = now();
        }

        $model->update($data);

        return back()->with('success', 'Контакт обновлён');
    }

    public function destroy(Request $request, int $contact): RedirectResponse
    {
        $actor = $this->crmActor($request);

        ClientContact::query()->visibleInCrm($actor)->findOrFail($contact)->delete();

        return back()->with('success', 'Контакт удалён');
    }

    /**
     * Распознать контакты из текстовых полей профиля CRM.
     *
     * Создаёт неактивные черновики: распознанное регуляркой подтверждает человек,
     * иначе письмо о финансах может уйти не тому.
     */
    public function importFromProfile(Request $request): RedirectResponse
    {
        $actor = $this->crmActor($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
        ], [], ['user_id' => 'партнёр']);

        $user = User::query()->visibleInCrm($actor)->findOrFail($data['user_id']);

        $result = $this->contacts->importFromProfile($user, $actor->id);

        $message = $result['created'] > 0
            ? "Распознано контактов: {$result['created']}. Проверьте данные и активируйте нужные."
            : 'В профиле не нашлось контактов с адресом электронной почты';

        return back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, User $actor, ?ClientContact $existing = null): array
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'full_name' => ['required', 'string', 'max:191'],
            'role' => ['required', Rule::in(ClientContactRole::values())],
            'position' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email:rfc', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
            'marketing_consent' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'user_id' => 'партнёр',
            'company_id' => 'контрагент',
            'full_name' => 'ФИО',
            'role' => 'роль',
            'position' => 'должность',
            'email' => 'электронная почта',
            'phone' => 'телефон',
            'is_primary' => 'основной контакт',
            'is_active' => 'активен',
            'marketing_consent' => 'согласие на рассылки',
            'notes' => 'заметка',
        ]);

        $user = User::query()->visibleInCrm($actor)->findOrFail($data['user_id']);
        $data['user_id'] = $user->id;

        if (filled($data['company_id'] ?? null)) {
            // Юрлицо должно принадлежать этому же партнёру: иначе контакт
            // «Ромашки» оказался бы в адресной книге «Одуванчика».
            $company = Company::query()
                ->visibleInCrm($actor)
                ->where('user_id', $user->id)
                ->findOrFail($data['company_id']);

            $data['company_id'] = $company->id;
        } else {
            $data['company_id'] = null;
        }

        $this->assertEmailIsUnique($data, $existing);

        return $data;
    }

    /**
     * Один адрес — одна карточка у партнёра.
     *
     * Уникальность не в БД: мягкое удаление и допустимость пустого адреса сделали
     * бы индекс бесполезным, поэтому проверка живёт здесь.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertEmailIsUnique(array $data, ?ClientContact $existing): void
    {
        if (blank($data['email'] ?? null)) {
            return;
        }

        $duplicate = ClientContact::query()
            ->where('user_id', $data['user_id'])
            ->where('email', mb_strtolower(trim($data['email'])))
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->getKey()))
            ->exists();

        if ($duplicate) {
            abort(422, 'У этого партнёра уже заведён контакт с такой электронной почтой');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ClientContact $contact): array
    {
        return [
            'id' => $contact->id,
            'user_id' => $contact->user_id,
            'company_id' => $contact->company_id,
            'full_name' => $contact->full_name,
            'role' => $contact->role->value,
            'role_label' => $contact->role->label(),
            'role_color' => $contact->role->color(),
            'position' => $contact->position,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'is_primary' => $contact->is_primary,
            'is_active' => $contact->is_active,
            'marketing_consent' => $contact->marketing_consent,
            'unsubscribed_at' => $contact->unsubscribed_at?->toDateTimeString(),
            'source' => $contact->source,
            'is_draft' => $contact->source === ClientContact::SOURCE_PROFILE_IMPORT && ! $contact->is_active,
            'notes' => $contact->notes,
        ];
    }
}
