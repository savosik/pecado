<?php

namespace App\Services\Contacts;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\CrmEmail;
use App\Models\EntitySubscription;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Кандидаты в справочник из того, что в базе уже есть.
 *
 * Самый быстрый путь наполнения: адреса и телефоны никуда не делись, они просто
 * лежат не карточками. Мастер их собирает, менеджер подтверждает.
 *
 * Подтверждение обязательно и не заменяется автоматикой: часть почт контрагентов —
 * общие ящики (info@, zakaz@), и человека за ними нет. Отличить их может только
 * тот, кто с этой компанией работает.
 */
class ContactSeeder
{
    /** Ящики, за которыми человека обычно нет. */
    private const IMPERSONAL_PREFIXES = [
        'info', 'office', 'mail', 'sales', 'zakaz', 'order', 'orders', 'shop',
        'support', 'admin', 'buh', 'buhgalteria', 'noreply', 'no-reply',
    ];

    /**
     * Кандидаты по всем источникам.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function candidates(?int $clientId = null, int $limit = 500): Collection
    {
        $known = $this->knownAddresses();

        return collect()
            ->concat($this->fromCompanies($clientId))
            ->concat($this->fromSubscriptions($clientId))
            ->concat($this->fromLetters($clientId))
            ->reject(fn (array $row): bool => isset($known[mb_strtolower((string) $row['email'])]))
            ->unique(fn (array $row): string => mb_strtolower((string) $row['email']))
            ->take($limit)
            ->values();
    }

    /**
     * Сводка по источникам — то, что показывает `--dry-run`.
     *
     * @return array<string, int>
     */
    public function summary(?int $clientId = null): array
    {
        $candidates = $this->candidates($clientId, 100000);

        return $candidates
            ->groupBy('source_label')
            ->map(fn (Collection $group): int => $group->count())
            ->all();
    }

    /**
     * Завести карточки по подтверждённым кандидатам.
     *
     * @param  array<int, string>  $emails
     * @return int сколько заведено
     */
    public function accept(array $emails, User $actor): int
    {
        $wanted = collect($emails)->map(fn ($email): string => mb_strtolower(trim((string) $email)))->all();

        $created = 0;

        foreach ($this->candidates(null, 100000) as $candidate) {
            if (! in_array(mb_strtolower((string) $candidate['email']), $wanted, true)) {
                continue;
            }

            $contact = new Contact([
                'full_name' => $candidate['full_name'],
                'email' => $candidate['email'],
                'phone' => $candidate['phone'],
                'is_active' => true,
            ]);

            $contact->client_user_id = $candidate['client_id'];
            $contact->source = ContactSource::DIRECTORY_IMPORT;
            $contact->created_by_user_id = $actor->getKey();
            $contact->updated_by_user_id = $actor->getKey();
            $contact->save();

            if ($candidate['company_id'] !== null) {
                ContactLink::query()->create([
                    'contact_id' => $contact->getKey(),
                    'subject_type' => Company::class,
                    'subject_id' => $candidate['company_id'],
                    'role' => ContactRole::MANAGER->value,
                    'client_user_id' => $candidate['client_id'],
                    'source' => ContactSource::DIRECTORY_IMPORT,
                    'created_by_user_id' => $actor->getKey(),
                ]);
            }

            $created++;
        }

        return $created;
    }

    /**
     * Адреса, которые уже есть в справочнике, — вместе с мягко удалёнными:
     * иначе повторный прогон вернёт то, что менеджер сознательно убрал.
     *
     * @return array<string, true>
     */
    private function knownAddresses(): array
    {
        return Contact::withTrashed()
            ->whereNotNull('email')
            ->pluck('email')
            ->mapWithKeys(fn ($email): array => [mb_strtolower((string) $email) => true])
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fromCompanies(?int $clientId): Collection
    {
        return Company::query()
            ->withoutGlobalScopes()
            ->whereNotNull('user_id')
            ->when($clientId, fn ($query) => $query->where('user_id', $clientId))
            ->whereNotNull('email')
            ->where('email', 'like', '%@%')
            ->get(['id', 'user_id', 'name', 'legal_name', 'email', 'phone'])
            ->map(fn (Company $company): array => [
                'email' => mb_strtolower(trim((string) $company->email)),
                'phone' => $company->phone,
                'full_name' => $this->guessName($company->email, (string) ($company->name ?: $company->legal_name)),
                'client_id' => (int) $company->user_id,
                'company_id' => (int) $company->getKey(),
                'source_label' => 'Почта контрагента',
                'impersonal' => $this->looksImpersonal((string) $company->email),
                'hint' => (string) ($company->name ?: $company->legal_name),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fromSubscriptions(?int $clientId): Collection
    {
        return EntitySubscription::query()
            ->where('channel', 'email')
            ->whereNotNull('destination')
            ->when($clientId, fn ($query) => $query->where('user_id', $clientId))
            ->get(['user_id', 'destination'])
            ->map(fn ($subscription): array => [
                'email' => mb_strtolower(trim((string) $subscription->destination)),
                'phone' => null,
                'full_name' => $this->guessName($subscription->destination, ''),
                'client_id' => (int) $subscription->user_id,
                'company_id' => null,
                'source_label' => 'Подписка из кабинета',
                'impersonal' => $this->looksImpersonal((string) $subscription->destination),
                'hint' => 'Клиент сам указал этот адрес',
            ]);
    }

    /**
     * Адресаты, которым уже писали. Самый достоверный источник: если человеку
     * писали, он существует.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fromLetters(?int $clientId): Collection
    {
        $fromJournal = SentEmail::query()
            ->whereNotNull('client_user_id')
            ->when($clientId, fn ($query) => $query->where('client_user_id', $clientId))
            ->where('sent_at', '>=', now()->subYear())
            ->get(['recipient', 'client_user_id'])
            ->map(fn (SentEmail $row): array => [
                'email' => mb_strtolower(trim((string) $row->recipient)),
                'phone' => null,
                'full_name' => $this->guessName($row->recipient, ''),
                'client_id' => (int) $row->client_user_id,
                'company_id' => null,
                'source_label' => 'Кому уже писали',
                'impersonal' => $this->looksImpersonal((string) $row->recipient),
                'hint' => 'Адрес встречался в журнале отправленных',
            ]);

        $fromDrafts = CrmEmail::query()
            ->whereNotNull('client_user_id')
            ->when($clientId, fn ($query) => $query->where('client_user_id', $clientId))
            ->get(['to', 'client_user_id'])
            ->flatMap(fn (CrmEmail $letter): array => collect((array) $letter->to)
                ->map(fn ($email): array => [
                    'email' => mb_strtolower(trim((string) $email)),
                    'phone' => null,
                    'full_name' => $this->guessName((string) $email, ''),
                    'client_id' => (int) $letter->client_user_id,
                    'company_id' => null,
                    'source_label' => 'Кому уже писали',
                    'impersonal' => $this->looksImpersonal((string) $email),
                    'hint' => 'Адрес встречался в письмах менеджеров',
                ])
                ->all());

        return $fromJournal->concat($fromDrafts);
    }

    /**
     * Имя из адреса — заготовка, которую менеджер поправит. «buh@romashka.ru»
     * даёт «Buh», и это лучше, чем пустая карточка.
     */
    private function guessName(?string $email, string $fallback): string
    {
        $local = trim(explode('@', (string) $email)[0]);
        $local = str_replace(['.', '_', '-'], ' ', $local);
        $local = trim(preg_replace('/\d+/', '', $local) ?? '');

        if ($local !== '') {
            return mb_convert_case($local, MB_CASE_TITLE, 'UTF-8');
        }

        return $fallback !== '' ? $fallback : 'Без имени';
    }

    /**
     * Похож ли адрес на общий ящик компании.
     *
     * Не отбрасываем такие кандидатуры, а помечаем: за `buh@` человек как раз
     * обычно стоит, а за `noreply@` — нет, и решать должен менеджер.
     */
    private function looksImpersonal(string $email): bool
    {
        $local = mb_strtolower(explode('@', $email)[0]);

        return in_array($local, self::IMPERSONAL_PREFIXES, true);
    }
}
