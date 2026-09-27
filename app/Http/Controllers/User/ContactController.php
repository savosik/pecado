<?php

namespace App\Http\Controllers\User;

use App\Enums\ContactRole;
use App\Enums\Crm\PreferredChannel;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Contacts\PartnerContactService;
use App\Services\Contacts\VCardExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    private const MAX_CONTACTS = PartnerContactService::MAX_CONTACTS;

    public function __construct(private readonly PartnerContactService $contacts) {}

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
            'data' => $contacts->map(fn (Contact $contact): array => $this->contacts->payload($contact))->all(),
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

        $contact = $this->contacts->create($partner, $request->all());

        return response()->json($this->contacts->payload($contact), 201);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $this->assertOwn($request, $contact);

        $contact = $this->contacts->update($request->user(), $contact, $request->all());

        return response()->json($this->contacts->payload($contact));
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

        $contact = $this->contacts->deactivate($request->user(), $contact);

        return response()->json($this->contacts->payload($contact));
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
        return $this->contacts->query($request->user());
    }

    private function assertOwn(Request $request, Contact $contact): void
    {
        abort_if((int) $contact->client_user_id !== (int) $request->user()->id, 404);
    }
}
