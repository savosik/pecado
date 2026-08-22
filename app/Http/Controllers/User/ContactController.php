<?php

namespace App\Http\Controllers\User;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Enums\Crm\PreferredChannel;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Services\Contacts\VCardExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Контакты партнёра в кабинете.
 *
 * Партнёр знает о смене бухгалтера раньше нашего менеджера, и ему же выгодно,
 * чтобы письма шли по адресу. Поэтому справочник открыт ему на правку — но
 * с одной границей: свою карточку он удаляет, нашу только гасит.
 *
 * Чужая карточка отвечает **404, а не 403**: 403 подтвердил бы, что она есть.
 */
class ContactController extends Controller
{
    /**
     * Потолок на партнёра. Не от жадности: справочник на тысячу человек
     * перестаёт быть справочником, а превращается в свалку.
     */
    private const MAX_CONTACTS = 50;

    public function index(Request $request): Response
    {
        return Inertia::render('User/Cabinet/Contacts/Index', [
            'roles' => ContactRole::options(),
            'channels' => PreferredChannel::options(),
            'limit' => self::MAX_CONTACTS,
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $contacts = $this->query($request)
            ->with('links.subject')
            ->orderBy('full_name')
            ->get();

        return response()->json([
            'data' => $contacts->map(fn (Contact $contact): array => $this->payload($contact))->all(),
            'companies' => Company::query()
                ->where('user_id', $request->user()->id)
                ->orderBy('name')
                ->get(['id', 'name', 'legal_name'])
                ->map(fn (Company $company): array => [
                    'id' => (int) $company->getKey(),
                    'name' => (string) ($company->name ?: $company->legal_name),
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $partner = $request->user();

        if ($this->query($request)->count() >= self::MAX_CONTACTS) {
            return response()->json([
                'message' => 'Больше '.self::MAX_CONTACTS.' контактов в кабинете не поместится. Удалите ненужные или напишите менеджеру.',
            ], 422);
        }

        $data = $this->validated($request);

        $contact = new Contact($data['attributes']);
        $contact->client_user_id = $partner->id;
        $contact->source = ContactSource::SELF;
        $contact->partner_touched_at = now();
        $contact->created_by_user_id = $partner->id;
        $contact->updated_by_user_id = $partner->id;
        $contact->save();

        $this->syncCompanyLink($contact, $partner->id, $data['company_id'], $data['role']);

        return response()->json($this->payload($contact->fresh('links.subject')), 201);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $this->assertOwn($request, $contact);

        $partner = $request->user();
        $data = $this->validated($request);

        $contact->fill($data['attributes']);
        // Отметка нужна менеджеру: он видит, что данные свежие и не от него.
        $contact->partner_touched_at = now();
        $contact->updated_by_user_id = $partner->id;
        $contact->save();

        $this->syncCompanyLink($contact, $partner->id, $data['company_id'], $data['role']);

        return response()->json($this->payload($contact->fresh('links.subject')));
    }

    /**
     * Удалить можно только свою карточку.
     *
     * Нашу партнёр не удаляет: она может быть заведена по разговору с менеджером
     * и связана с письмами. Для неё есть «Больше не работает».
     */
    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        $this->assertOwn($request, $contact);

        if (! $contact->source->belongsToPartner()) {
            return response()->json([
                'message' => 'Этот контакт завёл ваш менеджер. Его можно пометить «больше не работает», но не удалить.',
            ], 422);
        }

        $contact->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * «Больше не работает» — то, что партнёр делает вместо удаления нашей карточки.
     */
    public function deactivate(Request $request, Contact $contact): JsonResponse
    {
        $this->assertOwn($request, $contact);

        $contact->forceFill([
            'is_active' => false,
            'partner_touched_at' => now(),
            'updated_by_user_id' => $request->user()->id,
        ])->save();

        return response()->json($this->payload($contact->fresh('links.subject')));
    }

    public function avatar(Request $request, Contact $contact): JsonResponse
    {
        $this->assertOwn($request, $contact);

        Validator::make($request->all(), [
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:20480'],
        ], [
            'avatar.required' => 'Выберите файл.',
            'avatar.image' => 'Это не изображение.',
            'avatar.mimes' => 'Подойдёт JPEG, PNG или WebP.',
            'avatar.max' => 'Файл больше 20 МБ не поместится.',
        ])->validate();

        $contact->addMediaFromRequest('avatar')->toMediaCollection(Contact::AVATAR_COLLECTION);
        $contact->forceFill(['partner_touched_at' => now()])->save();

        return response()->json(['avatar_url' => $contact->fresh()->avatarUrl()]);
    }

    /**
     * Партнёру телефонная книга нужна ровно так же, как менеджеру.
     */
    public function vcard(Request $request): StreamedResponse
    {
        $contacts = $this->query($request)->with(['client', 'links.subject'])->orderBy('full_name')->get();

        return app(VCardExporter::class)->many($contacts);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Contact>
     */
    private function query(Request $request)
    {
        return Contact::query()
            ->where('client_user_id', $request->user()->id)
            ->whereNull('merged_into_id');
    }

    private function assertOwn(Request $request, Contact $contact): void
    {
        abort_if((int) $contact->client_user_id !== (int) $request->user()->id, 404);
    }

    /**
     * @return array{attributes: array<string, mixed>, company_id: int|null, role: ContactRole}
     */
    private function validated(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:191'],
            'greeting_name' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_extra' => ['nullable', 'string', 'max:50'],
            'telegram' => ['nullable', 'string', 'max:100'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'instagram' => ['nullable', 'string', 'max:100'],
            'birthday' => ['nullable', 'date'],
            'birthday_has_year' => ['boolean'],
            'preferred_channel' => ['nullable', \Illuminate\Validation\Rule::enum(PreferredChannel::class)],
            'is_active' => ['boolean'],
            'company_id' => ['nullable', 'integer'],
            'role' => ['nullable', \Illuminate\Validation\Rule::enum(ContactRole::class)],
        ], [
            'full_name.required' => 'Укажите ФИО.',
            'email.email' => 'Это не похоже на адрес электронной почты.',
            'birthday.date' => 'Дата рождения указана неверно.',
        ])->after(function ($validator) use ($request): void {
            // Человек без единого способа связи бесполезен: ни позвонить,
            // ни написать, ни выгрузить в телефон.
            if (blank($request->input('email')) && blank($request->input('phone'))) {
                $validator->errors()->add('phone', 'Укажите телефон или почту — иначе с человеком не связаться.');
            }
        })->validate();

        $companyId = null;

        if (filled($validated['company_id'] ?? null)) {
            // Юрлицо должно быть своим: чужое даёт пустоту, а не чужую привязку.
            $companyId = Company::query()
                ->where('user_id', $request->user()->id)
                ->whereKey((int) $validated['company_id'])
                ->value('id');
        }

        return [
            'attributes' => collect($validated)->only([
                'full_name', 'greeting_name', 'position', 'email', 'phone', 'phone_extra',
                'telegram', 'whatsapp', 'instagram', 'birthday', 'birthday_has_year',
                'preferred_channel', 'is_active',
            ])->all(),
            'company_id' => $companyId === null ? null : (int) $companyId,
            'role' => ContactRole::tryFrom((string) ($validated['role'] ?? '')) ?? ContactRole::MANAGER,
        ];
    }

    /**
     * Привязка к юрлицу партнёра: одна на карточку в кабинете.
     *
     * Больше одной роли партнёру не даём — это усложнение, которое в кабинете
     * никому не нужно, а менеджер при необходимости добавит в CRM.
     */
    private function syncCompanyLink(Contact $contact, int $partnerId, ?int $companyId, ContactRole $role): void
    {
        $contact->links()->where('subject_type', Company::class)->delete();

        if ($companyId === null) {
            return;
        }

        ContactLink::query()->updateOrCreate([
            'contact_id' => $contact->getKey(),
            'subject_type' => Company::class,
            'subject_id' => $companyId,
            'role' => $role->value,
        ], [
            'client_user_id' => $partnerId,
            'source' => ContactSource::SELF,
            'created_by_user_id' => $partnerId,
        ]);
    }

    /**
     * Карточка для кабинета.
     *
     * Заметка менеджера сюда не попадает никогда: там пишут «требует особого
     * подхода» и подобное.
     *
     * @return array<string, mixed>
     */
    private function payload(Contact $contact): array
    {
        $companyLink = $contact->links->firstWhere('subject_type', Company::class);

        return [
            'id' => (int) $contact->getKey(),
            'full_name' => $contact->full_name,
            'greeting_name' => $contact->greeting_name,
            'position' => $contact->position,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'phone_extra' => $contact->phone_extra,
            'telegram' => $contact->telegram,
            'whatsapp' => $contact->whatsapp,
            'instagram' => $contact->instagram,
            'birthday' => $contact->birthday?->toDateString(),
            'birthday_has_year' => (bool) $contact->birthday_has_year,
            'preferred_channel' => $contact->preferred_channel?->value,
            'preferred_channel_label' => $contact->preferred_channel?->label(),
            'is_active' => (bool) $contact->is_active,
            'avatar_url' => $contact->avatarUrl(),
            'is_mine' => $contact->source->belongsToPartner(),
            'source_label' => $contact->source->belongsToPartner() ? 'Ваш контакт' : 'Завёл менеджер',
            'company_id' => $companyLink === null ? null : (int) $companyLink->subject_id,
            'role' => $companyLink?->role->value,
            'role_label' => $companyLink?->role->label(),
        ];
    }
}
