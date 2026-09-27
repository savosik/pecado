<?php

namespace App\Services\Contacts;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Enums\Crm\PreferredChannel;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Контакты партнёра (люди на его стороне) — одна реализация для кабинета и API v1.
 *
 * Партнёр заводит своих людей и правит любые карточки своего аккаунта; карточку,
 * заведённую менеджером, он не удаляет, а помечает «больше не работает».
 */
class PartnerContactService
{
    public const MAX_CONTACTS = 50;

    /**
     * @return Builder<Contact>
     */
    public function query(User $partner): Builder
    {
        return Contact::query()
            ->where('client_user_id', $partner->getKey())
            ->whereNull('merged_into_id');
    }

    /**
     * Проверка входа с разбором привязок к юрлицам партнёра.
     *
     * @param  array<string, mixed>  $input
     * @return array{attributes: array<string, mixed>, links: list<array{company_id: int, role: ContactRole}>}
     *
     * @throws ValidationException
     */
    public function validate(User $partner, array $input): array
    {
        $validated = Validator::make($input, [
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
            'preferred_channel' => ['nullable', Rule::enum(PreferredChannel::class)],
            'is_active' => ['boolean'],
            'company_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::enum(ContactRole::class)],
            // Один человек — бухгалтер в нескольких юрлицах партнёра: привязок
            // столько, сколько компаний. Старая пара company_id/role принимается
            // как одна привязка.
            'links' => ['nullable', 'array', 'max:50'],
            'links.*.company_id' => ['required', 'integer'],
            'links.*.role' => ['required', Rule::enum(ContactRole::class)],
        ], [
            'full_name.required' => 'Укажите ФИО.',
            'email.email' => 'Это не похоже на адрес электронной почты.',
            'birthday.date' => 'Дата рождения указана неверно.',
        ])->after(function ($validator) use ($input): void {
            // Человек без единого способа связи бесполезен: ни позвонить,
            // ни написать, ни выгрузить в телефон.
            if (blank($input['email'] ?? null) && blank($input['phone'] ?? null)) {
                $validator->errors()->add('phone', 'Укажите телефон или почту — иначе с человеком не связаться.');
            }
        })->validate();

        $requested = collect($validated['links'] ?? []);

        if ($requested->isEmpty() && filled($validated['company_id'] ?? null)) {
            $requested = collect([[
                'company_id' => (int) $validated['company_id'],
                'role' => (string) ($validated['role'] ?? ContactRole::MANAGER->value),
            ]]);
        }

        // Юрлицо должно быть своим: чужое отбрасывается молча — пустота,
        // а не чужая привязка.
        $own = Company::query()
            ->where('user_id', $partner->getKey())
            ->whereIn('id', $requested->pluck('company_id')->map(fn ($id) => (int) $id)->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $links = $requested
            ->filter(fn (array $link): bool => in_array((int) $link['company_id'], $own, true))
            ->map(fn (array $link): array => [
                'company_id' => (int) $link['company_id'],
                'role' => ContactRole::tryFrom((string) ($link['role'] ?? '')) ?? ContactRole::MANAGER,
            ])
            ->unique(fn (array $link): string => $link['company_id'].':'.$link['role']->value)
            ->values()
            ->all();

        return [
            'attributes' => collect($validated)->only([
                'full_name', 'greeting_name', 'position', 'email', 'phone', 'phone_extra',
                'telegram', 'whatsapp', 'instagram', 'birthday', 'birthday_has_year',
                'preferred_channel', 'is_active',
            ])->all(),
            'links' => $links,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException лимит контактов или неверные поля
     */
    public function create(User $partner, array $input): Contact
    {
        if ($this->query($partner)->count() >= self::MAX_CONTACTS) {
            throw ValidationException::withMessages([
                'full_name' => 'Больше '.self::MAX_CONTACTS.' контактов в кабинете не поместится. Удалите ненужные или напишите менеджеру.',
            ]);
        }

        $data = $this->validate($partner, $input);

        $contact = new Contact($data['attributes']);
        $contact->client_user_id = $partner->getKey();
        $contact->source = ContactSource::SELF;
        $contact->partner_touched_at = now();
        $contact->created_by_user_id = $partner->getKey();
        $contact->updated_by_user_id = $partner->getKey();
        $contact->save();

        $this->syncCompanyLinks($contact, (int) $partner->getKey(), $data['links']);

        return $contact->fresh('links.subject');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $partner, Contact $contact, array $input): Contact
    {
        $data = $this->validate($partner, $input);

        $contact->fill($data['attributes']);
        // Отметка нужна менеджеру: он видит, что данные свежие и не от него.
        $contact->partner_touched_at = now();
        $contact->updated_by_user_id = $partner->getKey();
        $contact->save();

        $this->syncCompanyLinks($contact, (int) $partner->getKey(), $data['links']);

        return $contact->fresh('links.subject');
    }

    /**
     * «Больше не работает» — то, что партнёр делает вместо удаления нашей карточки.
     */
    public function deactivate(User $partner, Contact $contact): Contact
    {
        $contact->forceFill([
            'is_active' => false,
            'partner_touched_at' => now(),
            'updated_by_user_id' => $partner->getKey(),
        ])->save();

        return $contact->fresh('links.subject');
    }

    /**
     * @param  list<array{company_id: int, role: ContactRole}>  $links
     */
    public function syncCompanyLinks(Contact $contact, int $partnerId, array $links): void
    {
        $contact->links()->where('subject_type', Company::class)->delete();

        foreach ($links as $link) {
            ContactLink::query()->updateOrCreate([
                'contact_id' => $contact->getKey(),
                'subject_type' => Company::class,
                'subject_id' => $link['company_id'],
                'role' => $link['role']->value,
            ], [
                'client_user_id' => $partnerId,
                'source' => ContactSource::SELF,
                'created_by_user_id' => $partnerId,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Contact $contact): array
    {
        $companyLinks = $contact->links
            ->where('subject_type', Company::class)
            ->sortBy(fn (ContactLink $link) => $link->subject?->name ?? '')
            ->values();
        $companyLink = $companyLinks->first();

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
            'links' => $companyLinks->map(fn (ContactLink $link): array => [
                'company_id' => (int) $link->subject_id,
                'company_name' => (string) ($link->subject?->name ?: $link->subject?->legal_name ?: ''),
                'role' => $link->role->value,
                'role_label' => $link->role->label(),
            ])->all(),
        ];
    }
}
