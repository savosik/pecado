<?php

namespace App\Services\Contacts;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\CrmEmail;
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
    public function __construct(private readonly PersonNameParser $names) {}

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
            ->concat($this->fromPartners($clientId))
            ->concat($this->fromCompanies($clientId))
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

        $summary = $candidates
            ->groupBy('source_label')
            ->map(fn (Collection $group): int => $group->count())
            ->all();

        // Безымянные показываем отдельной строкой: это не отбракованные,
        // а те, кого менеджер должен назвать сам.
        $unnamed = $candidates->filter(fn (array $row): bool => blank($row['full_name']))->count();

        if ($unnamed > 0) {
            $summary['— из них без имени человека'] = $unnamed;
        }

        return $summary;
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

            // «Допустимо Петров И.И. + емейл, недопустимо ООО Ручеек + емейл».
            // Кандидат, из карточки которого имя человека не вывелось, ждёт,
            // пока менеджер назовёт его сам, — справочник людей юрлицами
            // не наполняется.
            if (blank($candidate['full_name'])) {
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
     * Люди из карточек партнёров.
     *
     * Самая крупная жила: у индивидуального предпринимателя название карточки
     * в 1С **и есть** ФИО. На проде такую форму имеют 672 карточки из 839.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fromPartners(?int $clientId): Collection
    {
        return User::query()
            ->whereNotNull('email')
            ->where('email', 'like', '%@%')
            ->when($clientId, fn ($query) => $query->whereKey($clientId))
            ->get(['id', 'name', 'erp_name', 'email', 'phone'])
            ->map(fn (User $partner): array => [
                'email' => mb_strtolower(trim((string) $partner->email)),
                'phone' => $partner->phone,
                'full_name' => $this->names->parse((string) ($partner->erp_name ?: $partner->name)),
                'client_id' => (int) $partner->getKey(),
                'company_id' => null,
                'source_label' => 'Карточка партнёра',
                'impersonal' => $this->looksImpersonal((string) $partner->email),
                'hint' => (string) ($partner->erp_name ?: $partner->name),
            ]);
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
                'full_name' => $this->names->parse((string) ($company->name ?: $company->legal_name)),
                'client_id' => (int) $company->user_id,
                'company_id' => (int) $company->getKey(),
                'source_label' => 'Почта контрагента',
                'impersonal' => $this->looksImpersonal((string) $company->email),
                'hint' => (string) ($company->name ?: $company->legal_name),
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
                'full_name' => null,
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
                    'full_name' => null,
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
