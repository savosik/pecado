<?php

namespace App\Services\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Рабочий список справочника людей.
 *
 * Строка списка — **человек, а не привязка**. Если строкой сделать роль, Мария
 * Афонина, бухгалтер трёх юрлиц одного партнёра, займёт три строки, и менеджер
 * решит, что в базе дубли. Поэтому роли и «где» показываются агрегатом внутри
 * одной строки.
 */
class ContactListService
{
    /**
     * Страница списка в форме строк таблицы.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $paginator = $this->query($actor, $filters)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return $paginator->through(fn (Contact $contact): array => $this->row($contact));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Contact>
     */
    public function query(User $actor, array $filters): Builder
    {
        $scope = $filters['scope'] instanceof CrmScope
            ? $filters['scope']
            : CrmScope::tryFrom((string) ($filters['scope'] ?? '')) ?? CrmScope::DEPARTMENT;

        $query = Contact::query()
            ->scopedInCrm($actor, $scope)
            ->whereNull('merged_into_id')
            ->with([
                'client:id,name,erp_name',
                // Полиморфная догрузка с явным списком колонок: без morphWith
                // на каждую строку летит запрос на каждую привязку, и 25 строк
                // превращаются в сотню запросов.
                'links' => fn ($links) => $links->with(['subject' => fn (MorphTo $morph) => $morph->morphWith([
                    Company::class => [],
                    User::class => [],
                ])]),
            ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, (string) ($filters['sort'] ?? 'name'), (string) ($filters['direction'] ?? 'asc'));

        return $query;
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $digits = preg_replace('/\D+/', '', $search) ?? '';

            $query->where(function (Builder $inner) use ($search, $digits) {
                $inner->where('full_name', 'like', "%{$search}%")
                    ->orWhere('position', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");

                // Телефон ищем по цифрам: «+7 (912) 345-67-89» и «9123456789» —
                // один и тот же номер, и человек набирает то одно, то другое.
                if (mb_strlen($digits) >= 3) {
                    $inner->orWhere('phone_digits', 'like', "%{$digits}%");
                }
            });
        }

        if (filled($filters['client_id'] ?? null)) {
            $query->where('client_user_id', (int) $filters['client_id']);
        }

        if (filled($filters['role'] ?? null)) {
            $role = (string) $filters['role'];
            $query->whereHas('links', fn (Builder $links) => $links->where('role', $role));
        }

        if (filled($filters['company_id'] ?? null)) {
            $companyId = (int) $filters['company_id'];
            $query->whereHas('links', fn (Builder $links) => $links
                ->where('subject_type', Company::class)
                ->where('subject_id', $companyId));
        }

        if (filled($filters['source'] ?? null)) {
            $query->where('source', (string) $filters['source']);
        }

        if (! empty($filters['with_email'])) {
            $query->whereNotNull('email');
        }

        if (! empty($filters['with_phone'])) {
            $query->whereNotNull('phone_digits');
        }

        if (! empty($filters['with_birthday'])) {
            $query->whereNotNull('birthday');
        }

        // По умолчанию показываем действующих: неактивные — это уволившиеся,
        // и в рабочем списке они только мешают.
        if (($filters['activity'] ?? 'active') === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['activity'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'created' => $query->orderBy('created_at', $direction),
            // Ближайший день рождения: сортируем по месяцу и числу, потому что
            // год у половины людей вымышленный. Выражение зависит от движка —
            // тесты идут на SQLite, а прод на MySQL.
            'birthday' => $query->orderByRaw($this->birthdayOrderExpression().' '.$direction),
            default => $query->orderBy('full_name', $direction),
        };
    }

    /**
     * Выражение сортировки по дню рождения без учёта года.
     */
    private function birthdayOrderExpression(): string
    {
        return \Illuminate\Support\Facades\DB::getDriverName() === 'sqlite'
            ? "strftime('%m-%d', birthday)"
            : 'DATE_FORMAT(birthday, "%m-%d")';
    }

    /**
     * Строка таблицы.
     *
     * @return array<string, mixed>
     */
    public function row(Contact $contact): array
    {
        return [
            'id' => (int) $contact->getKey(),
            'full_name' => $contact->full_name,
            'greeting_name' => $contact->greeting_name,
            'position' => $contact->position,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'telegram' => $contact->telegram,
            'preferred_channel' => $contact->preferred_channel?->value,
            'preferred_channel_label' => $contact->preferred_channel?->label(),
            'birthday_label' => $this->birthdayLabel($contact),
            'is_active' => (bool) $contact->is_active,
            'avatar_url' => $contact->avatarUrl(),
            'source' => $contact->source->value,
            'source_badge' => $contact->source->badge(),
            'source_color' => $contact->source->color(),
            'partner_touched_at_label' => $contact->partner_touched_at?->format('d.m.Y'),
            'client' => $contact->client === null ? null : [
                'id' => (int) $contact->client->getKey(),
                'name' => (string) $contact->client->display_name,
            ],
            'roles' => $contact->links
                ->map(fn ($link): array => [
                    'value' => $link->role->value,
                    'label' => $link->role->label(),
                    'color' => $link->role->color(),
                ])
                ->unique('value')
                ->values()
                ->all(),
            'links' => $contact->links
                ->map(fn ($link): array => $this->linkRow($link))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkRow($link): array
    {
        $subject = $link->subject;

        return [
            'id' => (int) $link->getKey(),
            'role' => $link->role->value,
            'role_label' => $link->role->label(),
            'role_color' => $link->role->color(),
            'role_note' => $link->role_note,
            'is_primary' => (bool) $link->is_primary,
            'subject' => $subject === null
                ? null
                : \App\Support\Crm\CrmEntityMap::describe($subject),
        ];
    }

    /**
     * День рождения человека, у которого год может быть неизвестен.
     */
    private function birthdayLabel(Contact $contact): ?string
    {
        if ($contact->birthday === null) {
            return null;
        }

        return $contact->birthday_has_year
            ? $contact->birthday->format('d.m.Y')
            : $contact->birthday->translatedFormat('d F');
    }
}
