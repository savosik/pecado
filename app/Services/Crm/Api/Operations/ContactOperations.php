<?php

namespace App\Services\Crm\Api\Operations;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\ContactLinkService;
use App\Services\Crm\ContactListService;
use App\Services\Crm\Mail\PartnerAddressBook;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Справочник людей для ИИ-агента.
 *
 * Агент умеет то же, что менеджер: найти человека по адресу, завести карточку
 * по итогам разговора, привязать роль. Ключевая операция — `by_email`: разбирая
 * письмо, агент спрашивает «чей это адрес», и при промахе заводит карточку.
 * Со следующего письма подшивка идёт сама.
 */
class ContactOperations
{
    public function __construct(
        private readonly ContactListService $contacts,
        private readonly ContactLinkService $links,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', Contact::class);

        $filters = [
            'search' => $input->string('search'),
            'role' => $input->string('role'),
            'client_id' => $input->int('client_id'),
            'activity' => $input->string('activity') ?: 'active',
            'per_page' => min(100, max(1, (int) ($input->int('per_page') ?? 30))),
            'scope' => \App\Enums\Crm\CrmScope::DEPARTMENT,
        ];

        $page = $this->contacts->query($actor, $filters)->paginate($filters['per_page']);

        return [
            'data' => $page->getCollection()
                ->map(fn (Contact $contact): array => $this->contacts->row($contact))
                ->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, OperationInput $input): array
    {
        $contact = $this->find($actor, $input->int('contact'));

        return $this->contacts->row($contact->load(['client', 'links.subject']));
    }

    /**
     * Чей это адрес.
     *
     * Ради этого справочник и связан с почтой: агент, разбирая письмо, узнаёт
     * человека и партнёра одним запросом.
     *
     * @return array<string, mixed>
     */
    public function byEmail(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', Contact::class);

        $email = (string) $input->string('email');
        $book = app(PartnerAddressBook::class);

        $contact = $book->resolveContact($email);

        if ($contact !== null && ! $actor->can('view', $contact)) {
            $contact = null;
        }

        return [
            'found' => $contact !== null,
            'contact' => $contact === null ? null : $this->contacts->row($contact->load(['client', 'links.subject'])),
            // Партнёр находится и без карточки человека — по аккаунту или почте
            // юрлица. Агенту это важно: письмо всё равно подошьётся к клиенту.
            'client_user_id' => $contact?->client_user_id ?? $book->resolve($email),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('create', Contact::class);

        $clientId = null;

        if ($input->has('client_id')) {
            $clientId = User::query()->visibleInCrm($actor)->whereKey($input->int('client_id'))->value('id');
        }

        $contact = new Contact([
            'full_name' => (string) $input->string('full_name'),
            'greeting_name' => $input->string('greeting_name'),
            'position' => $input->string('position'),
            'email' => $input->string('email'),
            'phone' => $input->string('phone'),
            'telegram' => $input->string('telegram'),
            'is_active' => true,
        ]);

        $contact->client_user_id = $clientId === null ? null : (int) $clientId;
        $contact->source = ContactSource::MANUAL;
        $contact->created_by_user_id = $actor->getKey();
        $contact->updated_by_user_id = $actor->getKey();
        $contact->save();

        return $this->contacts->row($contact->fresh(['client', 'links.subject']));
    }

    /**
     * @return array<string, mixed>
     */
    public function update(User $actor, OperationInput $input): array
    {
        $contact = $this->find($actor, $input->int('contact'));

        Gate::forUser($actor)->authorize('update', $contact);

        foreach (['full_name', 'greeting_name', 'position', 'email', 'phone', 'telegram'] as $field) {
            if ($input->has($field)) {
                $contact->{$field} = $input->string($field);
            }
        }

        $contact->updated_by_user_id = $actor->getKey();
        $contact->save();

        return $this->contacts->row($contact->fresh(['client', 'links.subject']));
    }

    /**
     * @return array<string, mixed>
     */
    public function link(User $actor, OperationInput $input): array
    {
        $contact = $this->find($actor, $input->int('contact'));

        Gate::forUser($actor)->authorize('update', $contact);

        $this->links->link(
            $actor,
            $contact,
            (string) $input->string('entity_type'),
            (int) $input->int('entity_id'),
            ContactRole::from((string) $input->string('role')),
        );

        return $this->contacts->row($contact->fresh(['client', 'links.subject']));
    }

    /**
     * Адресная книга партнёра одним запросом.
     *
     * @return array<string, mixed>
     */
    public function forClient(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', Contact::class);

        $clientId = User::query()->visibleInCrm($actor)->whereKey($input->int('client'))->value('id');

        if ($clientId === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [(int) $input->int('client')]);
        }

        $contacts = Contact::query()
            ->where('client_user_id', $clientId)
            ->whereNull('merged_into_id')
            ->with(['client', 'links.subject'])
            ->orderBy('full_name')
            ->get();

        return [
            'data' => $contacts->map(fn (Contact $contact): array => $this->contacts->row($contact))->all(),
        ];
    }

    /**
     * Чужая карточка агенту не отдаётся — 404, как и в вебе.
     *
     * Именно ModelNotFoundException, а не abort(404): агентский контроллер
     * разбирает исключения по типу, а HttpException унаследован от RuntimeException
     * и уехал бы в 422 с пустым сообщением.
     */
    private function find(User $actor, ?int $id): Contact
    {
        $contact = Contact::query()->find((int) $id);

        if ($contact === null || ! $actor->can('view', $contact)) {
            throw (new ModelNotFoundException)->setModel(Contact::class, [(int) $id]);
        }

        return $contact;
    }
}
