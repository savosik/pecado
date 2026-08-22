<?php

namespace App\Http\Controllers\Crm;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Enums\Crm\CrmScope;
use App\Enums\Crm\PreferredChannel;
use App\Http\Requests\Crm\StoreContactRequest;
use App\Http\Requests\Crm\UpdateContactRequest;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\User;
use App\Services\Contacts\ContactDeduplicator;
use App\Services\Contacts\ContactSeeder;
use App\Services\Contacts\VCardExporter;
use App\Services\Crm\ContactLinkService;
use App\Services\Crm\ContactListService;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Справочник людей.
 *
 * Раздел отвечает на вопрос «кто эти люди и как с ними связаться»: контактные
 * лица партнёров и их контрагентов в одном месте, с ролями, каналами связи
 * и днями рождения.
 *
 * Границу видимости задаёт партнёр карточки: кто видит партнёра — видит его
 * людей. Чужой контакт отвечает 404, а не 403: 403 подтвердил бы существование.
 */
class ContactController extends CrmController
{
    public function __construct(
        private readonly ContactListService $contacts,
        private readonly ContactLinkService $links,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', Contact::class);

        $filters = $this->validateFilters($request);
        $scope = CrmScope::fromRequest($request, $actor);

        return Inertia::render('Crm/Pages/Contacts/Index', [
            'contacts' => $this->contacts->paginate($actor, [...$filters, 'scope' => $scope]),
            'filters' => [...$filters, 'scope' => $scope->value],
            'roles' => ContactRole::options(),
            'sources' => ContactSource::options(),
            'channels' => PreferredChannel::options(),
            'canSeeDepartment' => $this->seesDepartment($request),
            'can' => [
                'create' => $actor->can('crm-contacts.create'),
                'edit' => $actor->can('crm-contacts.edit'),
                'delete' => $actor->can('crm-contacts.delete'),
            ],
        ]);
    }

    public function show(Request $request, Contact $contact): Response
    {
        $actor = $this->crmActor($request);
        $this->assertVisible($actor, $contact);

        $contact->load(['client:id,name,erp_name', 'links.subject', 'createdBy:id,name']);

        return Inertia::render('Crm/Pages/Contacts/Show', [
            'contact' => $this->payload($contact),
            'letters' => $this->letters($contact),
            'roles' => ContactRole::options(),
            'channels' => PreferredChannel::options(),
            'linkableTypes' => $this->linkableTypes(),
            'can' => [
                'edit' => $actor->can('update', $contact),
                'delete' => $actor->can('delete', $contact),
            ],
        ]);
    }

    /**
     * Контакты одной сущности — для врезки в её карточке.
     */
    public function forEntity(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', Contact::class);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(CrmEntityMap::contactLinkableTypes())],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        $subject = app(\App\Services\Crm\CrmEntityResolver::class)
            ->resolveForActor($actor, $validated['entity_type'], (int) $validated['entity_id']);

        $links = ContactLink::query()
            ->forSubject($subject)
            ->with(['contact' => fn ($query) => $query->with('client:id,name,erp_name')])
            ->get()
            ->filter(fn (ContactLink $link) => $link->contact !== null);

        // Общие контакты партнёра: у юрлица бухгалтера может не быть, а у партнёра
        // он есть, и менеджеру важно видеть обоих в одном месте.
        $partnerContacts = collect();
        $clientId = CrmEntityMap::clientIdFor($subject);

        if ($clientId !== null) {
            $partnerContacts = Contact::query()
                ->visibleInCrm($actor)
                ->where('client_user_id', $clientId)
                ->whereNull('merged_into_id')
                ->whereDoesntHave('links', fn ($query) => $query
                    ->where('subject_type', $subject::class)
                    ->where('subject_id', $subject->getKey()))
                ->with('links.subject')
                ->orderBy('full_name')
                ->limit(50)
                ->get();
        }

        return response()->json([
            'data' => $links->map(fn (ContactLink $link): array => [
                'link_id' => (int) $link->getKey(),
                'role' => $link->role->value,
                'role_label' => $link->role->label(),
                'role_color' => $link->role->color(),
                'role_note' => $link->role_note,
                'is_primary' => (bool) $link->is_primary,
                'contact' => $this->contacts->row($link->contact),
            ])->values()->all(),
            'partner_contacts' => $partnerContacts
                ->map(fn (Contact $contact): array => $this->contacts->row($contact))
                ->values()
                ->all(),
            'roles' => ContactRole::options(),
        ]);
    }

    /**
     * Выгрузка одной карточки в телефон.
     */
    public function vcard(Request $request, Contact $contact): StreamedResponse
    {
        $this->assertVisible($this->crmActor($request), $contact);

        $contact->load(['client', 'links.subject']);

        return app(VCardExporter::class)->one($contact);
    }

    /**
     * Выгрузка текущей выборки списка.
     *
     * Отдаём ровно то, что менеджер видит на экране: фильтры те же, что у списка.
     */
    public function vcardBatch(Request $request): StreamedResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', Contact::class);

        $filters = $this->validateFilters($request);
        $scope = CrmScope::fromRequest($request, $actor);

        $contacts = $this->contacts
            ->query($actor, [...$filters, 'scope' => $scope])
            ->limit(VCardExporter::MAX_CONTACTS)
            ->get();

        return app(VCardExporter::class)->many($contacts, $request->boolean('photos'));
    }

    /**
     * Кандидаты в справочник из данных, которые в базе уже есть.
     *
     * Подтверждает менеджер, а не автоматика: часть почт контрагентов — общие
     * ящики (info@, zakaz@), и человека за ними нет. Отличить может только тот,
     * кто с этой компанией работает.
     */
    public function candidates(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('create', Contact::class);

        $validated = $request->validate([
            'client_id' => ['nullable', 'integer'],
        ]);

        $clientId = $this->resolveClientId($actor, $validated['client_id'] ?? null);

        $candidates = app(ContactSeeder::class)
            ->candidates($clientId, 300)
            // Показываем только тех, чей партнёр актору виден: мастер не повод
            // увидеть чужую базу.
            ->filter(fn (array $row): bool => $this->clientVisible($actor, $row['client_id']))
            ->values();

        return response()->json([
            'data' => $candidates->all(),
            'total' => $candidates->count(),
        ]);
    }

    public function acceptCandidates(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('create', Contact::class);

        $validated = $request->validate([
            'emails' => ['required', 'array', 'min:1', 'max:300'],
            'emails.*' => ['required', 'string'],
        ], [
            'emails.required' => 'Отметьте хотя бы одного кандидата.',
        ]);

        $created = app(ContactSeeder::class)->accept($validated['emails'], $actor);

        return response()->json([
            'created' => $created,
            'message' => 'Заведено карточек: '.$created,
        ]);
    }

    /**
     * Похожие карточки — подсказка при создании.
     *
     * Подсказка, а не запрет: однофамильцы бывают, и решать должен человек.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', Contact::class);

        $validated = $request->validate([
            'email' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'full_name' => ['nullable', 'string', 'max:191'],
            'client_id' => ['nullable', 'integer'],
        ]);

        $similar = app(ContactDeduplicator::class)
            ->similarTo(
                $validated['email'] ?? null,
                $validated['phone'] ?? null,
                $validated['full_name'] ?? null,
                $this->resolveClientId($actor, $validated['client_id'] ?? null),
            )
            ->filter(fn (Contact $contact): bool => $actor->can('view', $contact));

        return response()->json([
            'data' => $similar->map(fn (Contact $contact): array => $this->contacts->row($contact))->values()->all(),
        ]);
    }

    /**
     * Экран «Возможные дубли».
     */
    public function duplicatePairs(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', Contact::class);

        $pairs = app(ContactDeduplicator::class)->pairs($actor);

        return response()->json([
            'data' => $pairs->map(fn (array $group): array => [
                'winner' => $this->contacts->row($group['winner']),
                'duplicates' => $group['duplicates']
                    ->map(fn (Contact $contact): array => $this->contacts->row($contact))
                    ->all(),
            ])->all(),
        ]);
    }

    /**
     * Слить дубль в победителя.
     */
    public function merge(Request $request, Contact $contact): JsonResponse
    {
        $actor = $this->crmActor($request);
        $this->assertVisible($actor, $contact);

        $validated = $request->validate([
            'duplicate_id' => ['required', 'integer', 'different:'.$contact->getKey()],
        ], [
            'duplicate_id.required' => 'Укажите, какую карточку слить.',
        ]);

        $duplicate = Contact::query()->findOrFail((int) $validated['duplicate_id']);
        $this->assertVisible($actor, $duplicate);

        app(ContactDeduplicator::class)->merge($contact, $duplicate);

        return response()->json([
            'contact' => $this->payload($contact->fresh(['client', 'links.subject'])),
            'message' => 'Карточки слиты',
        ]);
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('create', Contact::class);

        $validated = $request->validated();

        $contact = new Contact($this->attributes($validated));
        $contact->client_user_id = $this->resolveClientId($actor, $validated['client_id'] ?? null);
        $contact->source = ContactSource::MANUAL;
        $contact->created_by_user_id = $actor->getKey();
        $contact->updated_by_user_id = $actor->getKey();
        $contact->save();

        if (filled($validated['entity_type'] ?? null)) {
            $this->links->link(
                $actor,
                $contact,
                (string) $validated['entity_type'],
                (int) $validated['entity_id'],
                ContactRole::from((string) $validated['role']),
                $validated['role_note'] ?? null,
                (bool) ($validated['is_primary'] ?? false),
            );
        }

        return response()->json($this->payload($contact->fresh(['client', 'links.subject'])), 201);
    }

    public function update(UpdateContactRequest $request, Contact $contact): JsonResponse
    {
        $actor = $this->crmActor($request);
        $this->assertVisible($actor, $contact);

        $validated = $request->validated();

        $contact->fill($this->attributes($validated));

        if (array_key_exists('client_id', $validated)) {
            $contact->client_user_id = $this->resolveClientId($actor, $validated['client_id']);
        }

        $contact->updated_by_user_id = $actor->getKey();
        $contact->save();

        return response()->json($this->payload($contact->fresh(['client', 'links.subject'])));
    }

    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        $this->assertVisible($this->crmActor($request), $contact);

        $contact->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Аватар: загрузка и замена. Коллекция однофайловая, старое фото уходит само.
     */
    public function avatar(Request $request, Contact $contact): JsonResponse
    {
        $this->assertVisible($this->crmActor($request), $contact);

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:20480'],
        ], [
            'avatar.required' => 'Выберите файл.',
            'avatar.image' => 'Это не изображение.',
            'avatar.mimes' => 'Подойдёт JPEG, PNG или WebP.',
            'avatar.max' => 'Файл больше 20 МБ не поместится.',
        ]);

        $contact->addMediaFromRequest('avatar')->toMediaCollection(Contact::AVATAR_COLLECTION);

        return response()->json(['avatar_url' => $contact->fresh()->avatarUrl()]);
    }

    public function deleteAvatar(Request $request, Contact $contact): JsonResponse
    {
        $this->assertVisible($this->crmActor($request), $contact);

        $contact->clearMediaCollection(Contact::AVATAR_COLLECTION);

        return response()->json(['avatar_url' => null]);
    }

    /**
     * Привязать человека к сущности с ролью.
     */
    public function link(Request $request, Contact $contact): JsonResponse
    {
        $actor = $this->crmActor($request);
        $this->assertVisible($actor, $contact);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(CrmEntityMap::contactLinkableTypes())],
            'entity_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', Rule::enum(ContactRole::class)],
            'role_note' => ['nullable', 'string', 'max:191'],
            'is_primary' => ['boolean'],
        ], [
            'entity_type.required' => 'Укажите, к кому привязать контакт.',
            'role.required' => 'Выберите роль: кем человек приходится этой карточке.',
        ]);

        $this->links->link(
            $actor,
            $contact,
            (string) $validated['entity_type'],
            (int) $validated['entity_id'],
            ContactRole::from((string) $validated['role']),
            $validated['role_note'] ?? null,
            (bool) ($validated['is_primary'] ?? false),
        );

        return response()->json($this->payload($contact->fresh(['client', 'links.subject'])));
    }

    public function unlink(Request $request, Contact $contact, ContactLink $link): JsonResponse
    {
        $this->assertVisible($this->crmActor($request), $contact);

        if ((int) $link->contact_id !== (int) $contact->getKey()) {
            abort(404);
        }

        $this->links->unlink($link);

        return response()->json($this->payload($contact->fresh(['client', 'links.subject'])));
    }

    /**
     * Переписка с этим человеком.
     *
     * Ради этого справочник и связан с почтой: раньше письмо бухгалтеру
     * подшивалось к партнёру, и открыть карточку человека, чтобы увидеть,
     * что ему писали, было нельзя.
     *
     * @return array<int, array<string, mixed>>
     */
    private function letters(Contact $contact): array
    {
        return \App\Models\CrmEmail::query()
            ->where('contact_id', $contact->getKey())
            ->latest('id')
            ->limit(20)
            ->get(['id', 'subject', 'status', 'sent_at', 'created_at', 'to'])
            ->map(fn ($letter): array => [
                'id' => (int) $letter->getKey(),
                'subject' => $letter->subject,
                'status_label' => $letter->status->label(),
                'status_color' => $letter->status->color(),
                'date_label' => ($letter->sent_at ?? $letter->created_at)?->format('d.m.Y H:i'),
                'url' => route('crm.emails.index', ['email' => $letter->getKey()]),
            ])
            ->all();
    }

    /**
     * Чужая карточка отвечает 404, а не 403.
     *
     * Право на раздел уже проверено middleware маршрута, поэтому отказ здесь
     * означает ровно одно — человек не из моих партнёров. Отвечать 403 значило бы
     * подтвердить, что такая карточка существует.
     */
    private function assertVisible(User $actor, Contact $contact): void
    {
        abort_unless($actor->can('view', $contact), 404);
    }

    private function clientVisible(User $actor, ?int $clientId): bool
    {
        if ($clientId === null) {
            return $actor->can('crm-clients-all.view');
        }

        return User::query()->visibleInCrm($actor)->whereKey($clientId)->exists();
    }

    /**
     * Партнёр карточки резолвится через скоуп: приписать человека чужому
     * партнёру нельзя, даже зная его id.
     */
    private function resolveClientId(User $actor, mixed $clientId): ?int
    {
        if (blank($clientId)) {
            return null;
        }

        return User::query()->visibleInCrm($actor)->whereKey((int) $clientId)->value('id');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return collect($validated)->only([
            'full_name',
            'greeting_name',
            'position',
            'email',
            'phone',
            'phone_extra',
            'telegram',
            'whatsapp',
            'instagram',
            'website',
            'preferred_channel',
            'birthday',
            'birthday_has_year',
            'is_active',
            'marketing_consent',
            'notes',
        ])->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function linkableTypes(): array
    {
        return array_map(fn (string $type): array => [
            'value' => $type,
            'label' => CrmEntityMap::labelFor($type),
        ], CrmEntityMap::contactLinkableTypes());
    }

    /**
     * Карточка человека для фронта.
     *
     * @return array<string, mixed>
     */
    private function payload(Contact $contact): array
    {
        return [
            ...$this->contacts->row($contact),
            'phone_extra' => $contact->phone_extra,
            'whatsapp' => $contact->whatsapp,
            'instagram' => $contact->instagram,
            'website' => $contact->website,
            'birthday' => $contact->birthday?->toDateString(),
            'birthday_has_year' => (bool) $contact->birthday_has_year,
            'marketing_consent' => (bool) $contact->marketing_consent,
            'unsubscribed_at_label' => $contact->unsubscribed_at?->format('d.m.Y'),
            'notes' => $contact->notes,
            'source_label' => $contact->source->label(),
            'created_by' => $contact->createdBy?->name,
            'created_at_label' => $contact->created_at?->format('d.m.Y'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:190'],
            'role' => ['nullable', Rule::enum(ContactRole::class)],
            'source' => ['nullable', Rule::enum(ContactSource::class)],
            'client_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'activity' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'with_email' => ['nullable', 'boolean'],
            'with_phone' => ['nullable', 'boolean'],
            'with_birthday' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'created', 'birthday'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
        ]);

        return [
            'search' => $validated['search'] ?? null,
            'role' => $validated['role'] ?? null,
            'source' => $validated['source'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'company_id' => $validated['company_id'] ?? null,
            'activity' => $validated['activity'] ?? 'active',
            'with_email' => (bool) ($validated['with_email'] ?? false),
            'with_phone' => (bool) ($validated['with_phone'] ?? false),
            'with_birthday' => (bool) ($validated['with_birthday'] ?? false),
            'sort' => $validated['sort'] ?? 'name',
            'direction' => $validated['direction'] ?? 'asc',
            'per_page' => min(max((int) ($validated['per_page'] ?? 25), 10), 100),
        ];
    }
}
