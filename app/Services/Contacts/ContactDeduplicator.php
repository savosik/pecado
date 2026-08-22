<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\CrmEmail;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Дубли людей и их слияние.
 *
 * Модель «человек + привязки» снимает структурную причину дублей: бухгалтер
 * двух юрлиц — одна карточка. Остаются человеческие: его заведут дважды из двух
 * разных карточек, а мастер наполнения даёт кандидатов из четырёх источников,
 * где пересечения гарантированы.
 *
 * Совпадение — подсказка, а не запрет: однофамильцы бывают, и решать должен
 * человек.
 */
class ContactDeduplicator
{
    /**
     * Похожие карточки: тот же телефон, та же почта или то же имя у того же партнёра.
     *
     * @return Collection<int, Contact>
     */
    public function similar(Contact $contact, int $limit = 5): Collection
    {
        $digits = $contact->phone_digits;
        $email = $contact->email;
        $name = $this->normalizeName($contact->full_name);

        return Contact::query()
            ->whereKeyNot($contact->getKey())
            ->whereNull('merged_into_id')
            ->where(function ($query) use ($digits, $email, $name, $contact) {
                if ($digits !== null) {
                    $query->orWhere('phone_digits', $digits);
                }

                if ($email !== null) {
                    $query->orWhere('email', $email);
                }

                // Совпадение имени считается только внутри одного партнёра:
                // «Иванов Иван» у разных клиентов — разные люди.
                if ($name !== '' && $contact->client_user_id !== null) {
                    $query->orWhere(fn ($inner) => $inner
                        ->where('client_user_id', $contact->client_user_id)
                        ->whereRaw('LOWER(full_name) = ?', [$name]));
                }
            })
            ->limit($limit)
            ->get();
    }

    /**
     * Похожие на ещё не сохранённую карточку — подсказка в форме создания.
     *
     * @return Collection<int, Contact>
     */
    public function similarTo(?string $email, ?string $phone, ?string $fullName, ?int $clientId): Collection
    {
        $probe = new Contact([
            'full_name' => (string) $fullName,
            'email' => $email,
            'phone' => $phone,
        ]);
        $probe->client_user_id = $clientId;
        $probe->phone_digits = Contact::digitsOf($phone);
        $probe->email = filled($email) ? mb_strtolower(trim($email)) : null;

        return $this->similar($probe);
    }

    /**
     * Пары, похожие друг на друга, — экран «Возможные дубли».
     *
     * @return Collection<int, array{winner: Contact, duplicates: Collection<int, Contact>}>
     */
    public function pairs(User $actor, int $limit = 50): Collection
    {
        $contacts = Contact::query()
            ->visibleInCrm($actor)
            ->whereNull('merged_into_id')
            ->orderBy('id')
            ->limit(2000)
            ->get();

        $seen = [];
        $groups = collect();

        foreach ($contacts as $contact) {
            if (isset($seen[$contact->getKey()])) {
                continue;
            }

            $duplicates = $contacts
                ->filter(fn (Contact $other): bool => $other->getKey() !== $contact->getKey()
                    && ! isset($seen[$other->getKey()])
                    && $this->looksSame($contact, $other));

            if ($duplicates->isEmpty()) {
                continue;
            }

            $seen[$contact->getKey()] = true;

            foreach ($duplicates as $duplicate) {
                $seen[$duplicate->getKey()] = true;
            }

            $groups->push(['winner' => $contact, 'duplicates' => $duplicates->values()]);

            if ($groups->count() >= $limit) {
                break;
            }
        }

        return $groups;
    }

    /**
     * Слить дубль в победителя.
     *
     * Переезжает всё, что на дубль ссылается: привязки, письма, журнал.
     * Сам дубль не удаляется физически — он получает `merged_into_id`, чтобы
     * ссылка из старого отчёта не упёрлась в пустоту.
     */
    public function merge(Contact $winner, Contact $duplicate): void
    {
        if ($winner->is($duplicate)) {
            return;
        }

        DB::transaction(function () use ($winner, $duplicate): void {
            $this->moveLinks($winner, $duplicate);

            CrmEmail::query()->where('contact_id', $duplicate->getKey())
                ->update(['contact_id' => $winner->getKey()]);
            SentEmail::query()->where('contact_id', $duplicate->getKey())
                ->update(['contact_id' => $winner->getKey()]);

            // Пустые поля победителя добираем из дубля: иначе слияние теряло бы
            // телефон, который был только у проигравшего.
            $winner->fill(array_filter([
                'greeting_name' => $winner->greeting_name ?: $duplicate->greeting_name,
                'position' => $winner->position ?: $duplicate->position,
                'email' => $winner->email ?: $duplicate->email,
                'phone' => $winner->phone ?: $duplicate->phone,
                'phone_extra' => $winner->phone_extra ?: $duplicate->phone_extra,
                'telegram' => $winner->telegram ?: $duplicate->telegram,
                'whatsapp' => $winner->whatsapp ?: $duplicate->whatsapp,
                'instagram' => $winner->instagram ?: $duplicate->instagram,
                'website' => $winner->website ?: $duplicate->website,
                'notes' => $winner->notes ?: $duplicate->notes,
            ]));

            if ($winner->birthday === null && $duplicate->birthday !== null) {
                $winner->birthday = $duplicate->birthday;
                $winner->birthday_has_year = $duplicate->birthday_has_year;
            }

            if ($winner->client_user_id === null) {
                $winner->client_user_id = $duplicate->client_user_id;
            }

            $winner->save();

            $duplicate->forceFill(['merged_into_id' => $winner->getKey()])->save();
            $duplicate->delete();
        });
    }

    /**
     * Привязки дубля переезжают, кроме тех, что у победителя уже есть:
     * уникальный индекс не даст завести вторую такую же.
     */
    private function moveLinks(Contact $winner, Contact $duplicate): void
    {
        foreach ($duplicate->links()->get() as $link) {
            $exists = ContactLink::query()
                ->where('contact_id', $winner->getKey())
                ->where('subject_type', $link->subject_type)
                ->where('subject_id', $link->subject_id)
                ->where('role', $link->role->value)
                ->exists();

            if ($exists) {
                $link->delete();

                continue;
            }

            $link->forceFill(['contact_id' => $winner->getKey()])->save();
        }
    }

    private function looksSame(Contact $a, Contact $b): bool
    {
        if ($a->phone_digits !== null && $a->phone_digits === $b->phone_digits) {
            return true;
        }

        if ($a->email !== null && $a->email === $b->email) {
            return true;
        }

        return $a->client_user_id !== null
            && $a->client_user_id === $b->client_user_id
            && $this->normalizeName($a->full_name) === $this->normalizeName($b->full_name);
    }

    private function normalizeName(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''));
    }
}
