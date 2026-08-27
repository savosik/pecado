<?php

namespace App\Services\Contacts;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Enums\Crm\PaymentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\CrmComment;
use App\Models\User;
use App\Services\Crm\ClientProfileService;
use App\Support\Contacts\ManagerSheetReport;
use App\Support\Crm\ClientNameIndex;
use App\Support\PhoneFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Перенос таблицы менеджеров «клиент — контакты — условия — комментарий» в CRM.
 *
 * Таблица разобрана заранее в JSON: строка свободного текста вида
 * «тел: +7 … (Катерина-закуп) / почта / https://t.me/…» машиной не читается
 * надёжно, а ошибка здесь — чужой телефон у партнёра. Импортёр получает уже
 * названных людей с ролями и раскладывает их по местам:
 *
 *  - человек → `contacts` + привязка к партнёру (и к контрагенту, если он —
 *    тот самый ИП); телефон приводится к единому виду;
 *  - условия оплаты и документы → паспорт партнёра (`crm_client_profiles`);
 *  - особенности работы, каналы, почты для документов → заметки менеджера
 *    в том же паспорте, отдельной секцией с меткой;
 *  - прогноз заказа → комментарий в ленте партнёра: он устаревает за месяц,
 *    и в паспорте ему не место.
 *
 * Повторный прогон ничего не дублирует: людей находит по телефону, почте
 * и имени, секцию заметок — по метке, комментарий — по тексту.
 */
class ManagerSheetImporter
{
    /** Метка секции в заметках: по ней прогон узнаёт, что уже переносил. */
    public const NOTES_MARKER = '## Из таблицы менеджера';

    /** Префикс комментария-прогноза — для дедупликации при повторном прогоне. */
    public const FORECAST_PREFIX = 'Прогноз заказа (таблица менеджера';

    private ClientNameIndex $index;

    public function __construct(private readonly ClientProfileService $profiles) {}

    /**
     * @param  array<string, mixed>  $document  разобранная таблица: rows[] со строками
     * @param  array<string, User>  $authors  автор по ключу менеджера («kurochkina» → сотрудник)
     */
    /**
     * @param  bool  $orphans  людей из строк без партнёра в базе заводить «ничьими» карточками
     */
    public function import(array $document, array $authors, bool $dryRun = false, bool $overwrite = false, bool $orphans = false): ManagerSheetReport
    {
        $report = new ManagerSheetReport;
        $this->index = $this->buildIndex();

        $stamp = (string) ($document['exported_at'] ?? now()->toDateString());
        $rows = is_array($document['rows'] ?? null) ? $document['rows'] : [];

        foreach ($rows as $row) {
            $report->rowsTotal++;

            $line = (int) ($row['line'] ?? 0);
            $name = trim((string) ($row['client'] ?? ''));
            $author = $authors[(string) ($row['manager'] ?? '')] ?? null;

            if ($author === null) {
                $report->warnings[] = sprintf('строка %d: неизвестный менеджер «%s», пропущена', $line, $row['manager'] ?? '');

                continue;
            }

            // Явный идентификатор — для строк, где таблица и 1С называют партнёра
            // по-разному (жена вместо мужа, старое ИП вместо нового): их сверяет
            // человек и записывает в файл, а не угадывает индекс.
            $candidates = filled($row['client_id'] ?? null)
                ? [(int) $row['client_id']]
                : $this->index->find($name);

            if ($candidates === []) {
                $report->unmatched[] = ['line' => $line, 'name' => $name];

                if ($orphans) {
                    $dryRun
                        ? $this->previewOrphans($row, $author, $report)
                        : DB::transaction(fn () => $this->applyOrphans($row, $author, $stamp, $overwrite, $report));
                }

                continue;
            }

            if (count($candidates) > 1) {
                $report->ambiguous[] = ['line' => $line, 'name' => $name, 'candidates' => count($candidates)];

                continue;
            }

            $client = User::query()->find($candidates[0]);

            if ($client === null) {
                $report->unmatched[] = ['line' => $line, 'name' => $name.' (client_id='.$candidates[0].')'];

                continue;
            }

            $report->rowsMatched++;

            if ($dryRun) {
                $this->preview($row, $client, $report);

                continue;
            }

            DB::transaction(function () use ($row, $client, $author, $stamp, $overwrite, $report): void {
                $this->applyProfile($row, $client, $author, $stamp, $overwrite, $report);
                $this->applyForecast($row, $client, $author, $stamp, $report);
                $this->applyContacts($row, $client, $author, $overwrite, $report);
            });
        }

        return $report;
    }

    /**
     * Партнёры под рабочим наименованием из 1С и именем на сайте.
     *
     * Сотрудники в индекс не попадают: «Курочкина Елена» из таблицы нашла бы
     * учётку менеджера, а не клиента.
     */
    private function buildIndex(): ClientNameIndex
    {
        $index = new ClientNameIndex;

        User::query()
            ->where(fn ($query) => $query->whereNotNull('erp_id')->orWhereNotNull('personal_manager_id'))
            ->get(['id', 'name', 'erp_name'])
            ->each(function (User $user) use ($index): void {
                $index->add((int) $user->getKey(), ...array_filter([(string) $user->erp_name, (string) $user->name]));
            });

        return $index;
    }

    /**
     * В пробном прогоне считаем то же, что записали бы, — чтобы отчёт совпадал
     * с боевым, а не показывал «0 контактов» из-за раннего выхода.
     *
     * @param  array<string, mixed>  $row
     */
    private function preview(array $row, User $client, ManagerSheetReport $report): void
    {
        foreach ($this->people($row) as $person) {
            if ($this->findExisting($client, $person) === null) {
                $report->contactsCreated++;
                // Новому человеку гарантирована привязка к партнёру; привязки к юрлицам не предсказываем.
                $report->linksCreated++;
            } else {
                $report->contactsUpdated++;
            }
        }

        if ($this->notesBlock($row, '') !== null) {
            $report->profilesUpdated++;
        }

        if (filled($row['forecast'] ?? null)) {
            $report->commentsCreated++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function previewOrphans(array $row, User $author, ManagerSheetReport $report): void
    {
        foreach ($this->people($row) as $person) {
            $this->findOrphan($author, $person) === null
                ? $report->orphansCreated++
                : $report->orphansUpdated++;
        }
    }

    /**
     * Партнёра в базе нет — человек заводится «ничьим»: карточка видна автору
     * и тем, кто видит всю базу, а с появлением партнёра её привяжут вручную.
     *
     * Паспорта у такого партнёра нет, поэтому всё, что таблица знает о нём
     * (название, условия, документы, комментарий, прогноз), ложится в заметки
     * самого контакта — иначе эти сведения пропали бы вовсе.
     *
     * @param  array<string, mixed>  $row
     */
    private function applyOrphans(array $row, User $author, string $stamp, bool $overwrite, ManagerSheetReport $report): void
    {
        $partnerNote = $this->orphanNote($row, $stamp);

        foreach ($this->people($row) as $person) {
            $contact = $this->findOrphan($author, $person);
            $isNew = $contact === null;

            $contact ??= new Contact;

            $this->fillContact($contact, $person, $overwrite || $isNew);

            if ($isNew) {
                $contact->client_user_id = null;
                $contact->source = ContactSource::MANAGER_SHEET;
                $contact->is_active = true;
                $contact->created_by_user_id = $author->getKey();
            }

            if (! str_contains((string) $contact->notes, $partnerNote)) {
                $contact->notes = trim(rtrim((string) $contact->notes)."\n\n".$partnerNote);
            }

            $contact->updated_by_user_id = $author->getKey();
            $contact->save();

            $isNew ? $report->orphansCreated++ : $report->orphansUpdated++;
        }
    }

    /**
     * Ничья карточка того же автора: по телефону, почте или имени.
     *
     * @param  array<string, mixed>  $person
     */
    private function findOrphan(User $author, array $person): ?Contact
    {
        $digits = Contact::digitsOf($person['phone'] ?? null);
        $email = filled($person['email'] ?? null) ? mb_strtolower(trim((string) $person['email'])) : null;
        $name = trim((string) $person['full_name']);

        return Contact::query()
            ->whereNull('client_user_id')
            ->whereNull('merged_into_id')
            ->where('created_by_user_id', $author->getKey())
            ->where(function ($inner) use ($digits, $email, $name): void {
                $inner->whereRaw('1 = 0');

                if ($digits !== null) {
                    $inner->orWhere('phone_digits', $digits);
                }

                if ($email !== null) {
                    $inner->orWhere('email', $email);
                }

                $inner->orWhere('full_name', $name);
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * Что таблица знает о партнёре, которого нет в базе, — текстом для заметок контакта.
     *
     * @param  array<string, mixed>  $row
     */
    private function orphanNote(array $row, string $stamp): string
    {
        $lines = ['Партнёр по таблице менеджера ('.$this->humanDate($stamp).'): '.trim((string) ($row['client'] ?? '')).' — в базе сайта не найден.'];

        foreach ([
            'new_status' => 'Статус',
            'payment' => 'Условия оплаты',
            'docs' => 'Документы',
            'comment' => 'Особенности работы',
            'forecast' => 'Прогноз заказа',
        ] as $field => $label) {
            if (($value = $this->text($row[$field] ?? null)) !== null) {
                $lines[] = $label.': '.$value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Паспорт партнёра: структурные поля — только в пустые, заметки — секцией с меткой.
     *
     * @param  array<string, mixed>  $row
     */
    private function applyProfile(array $row, User $client, User $author, string $stamp, bool $overwrite, ManagerSheetReport $report): void
    {
        $profile = $this->profiles->forClient($client);
        $changes = [];

        $payment = trim((string) ($row['payment'] ?? ''));

        if ($payment !== '') {
            $fields = [
                'payment_terms' => Str::limit($payment, 250, '…'),
                'payment_type' => $this->paymentType($payment)?->value,
                'deferral_days' => $this->deferralDays($payment),
            ];

            foreach ($fields as $field => $value) {
                if ($value === null) {
                    continue;
                }

                if ($overwrite || $profile->getAttribute($field) === null) {
                    $changes[$field] = $value;
                }
            }
        }

        $block = $this->notesBlock($row, $stamp);

        if ($block !== null) {
            $existing = (string) $profile->notes_md;

            if ($existing === '') {
                $changes['notes_md'] = $block;
            } elseif (! str_contains($existing, self::NOTES_MARKER)) {
                $changes['notes_md'] = rtrim($existing)."\n\n".$block;
            } elseif ($overwrite) {
                $changes['notes_md'] = $this->replaceSection($existing, $block);
            }
        }

        if ($changes === []) {
            return;
        }

        $this->profiles->update($client, $changes, $author);
        $report->profilesUpdated++;
    }

    /**
     * Прогноз заказа — в ленту, а не в паспорт: через месяц он уже история.
     *
     * @param  array<string, mixed>  $row
     */
    private function applyForecast(array $row, User $client, User $author, string $stamp, ManagerSheetReport $report): void
    {
        $forecast = trim((string) ($row['forecast'] ?? ''));

        if ($forecast === '') {
            return;
        }

        $body = sprintf('%s, %s): %s', self::FORECAST_PREFIX, $this->humanDate($stamp), $forecast);

        $exists = CrmComment::query()
            ->where('commentable_type', User::class)
            ->where('commentable_id', $client->getKey())
            ->where('body', $body)
            ->exists();

        if ($exists) {
            return;
        }

        CrmComment::query()->create([
            'commentable_type' => User::class,
            'commentable_id' => $client->getKey(),
            'client_user_id' => $client->getKey(),
            'user_id' => $author->getKey(),
            'body' => $body,
            'is_pinned' => false,
        ]);

        $report->commentsCreated++;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyContacts(array $row, User $client, User $author, bool $overwrite, ManagerSheetReport $report): void
    {
        foreach ($this->people($row) as $person) {
            $person['full_name'] = $this->expandName((string) $person['full_name'], $client, ContactRole::tryFrom((string) ($person['role'] ?? '')));

            $contact = $this->findExisting($client, $person);
            $isNew = $contact === null;

            $contact ??= new Contact;

            $this->fillContact($contact, $person, $overwrite || $isNew);

            if ($isNew) {
                $contact->client_user_id = $client->getKey();
                $contact->source = ContactSource::MANAGER_SHEET;
                $contact->is_active = true;
                $contact->created_by_user_id = $author->getKey();
            }

            $contact->updated_by_user_id = $author->getKey();
            $contact->save();

            $isNew ? $report->contactsCreated++ : $report->contactsUpdated++;

            $role = ContactRole::tryFrom((string) ($person['role'] ?? '')) ?? ContactRole::MANAGER;

            $this->link($contact, $client, $role, (bool) ($person['primary'] ?? false), $client, $author, $report);

            $company = $this->companyFor($client, $person, $role);

            if ($company !== null) {
                // Свой ИП — собственник; чужое юрлицо партнёра (по подсказке) — в той же роли, что и у партнёра.
                $companyRole = $this->sameName($person, $company) ? ContactRole::OWNER : $role;

                $this->link($contact, $company, $companyRole, false, $client, $author, $report);
            }
        }
    }

    /**
     * Люди строки — только те, у кого есть имя: безымянный телефон в справочник
     * людей не попадает, он остаётся в заметках партнёра.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function people(array $row): array
    {
        $people = [];

        foreach ((array) ($row['contacts'] ?? []) as $person) {
            if (! is_array($person) || blank($person['full_name'] ?? null)) {
                continue;
            }

            $people[] = $person;
        }

        return $people;
    }

    /**
     * Полное ФИО из карточки партнёра, когда таблица записала человека коротко.
     *
     * «Иван Князев» у партнёра «Князев Иван Валерьевич ИП» — тот же человек,
     * и отчество берётся из 1С, а не выдумывается. Одно имя («Дмитрий»)
     * достраивается только у собственника: у ИП контактное лицо с именем
     * владельца — обычно сам владелец, а у ООО это ни о чём не говорит.
     */
    private function expandName(string $name, User $client, ?ContactRole $role): string
    {
        $full = app(PersonNameParser::class)->parse((string) ($client->erp_name ?: $client->name));

        if ($full === null) {
            return $name;
        }

        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $fullWords = preg_split('/\s+/u', $full) ?: [];

        if (count($fullWords) < 3) {
            return $name;
        }

        $lower = fn (string $word): string => mb_strtolower(str_replace('ё', 'е', $word));
        [$surname, $firstName] = [$lower($fullWords[0]), $lower($fullWords[1])];
        $given = array_map($lower, $words);
        sort($given);

        $pair = [$surname, $firstName];
        sort($pair);

        if (count($words) === 2 && $given === $pair) {
            return $full;
        }

        if (count($words) === 1 && $given[0] === $firstName && $role === ContactRole::OWNER) {
            return $full;
        }

        return $name;
    }

    /**
     * Тот же человек у того же партнёра: по телефону, по почте, по имени.
     *
     * Круг поиска — партнёр, а не вся база: однофамильцы у разных клиентов —
     * разные люди, и склеивать их нельзя.
     *
     * @param  array<string, mixed>  $person
     */
    private function findExisting(User $client, array $person): ?Contact
    {
        $query = Contact::query()
            ->where('client_user_id', $client->getKey())
            ->whereNull('merged_into_id');

        $digits = Contact::digitsOf($person['phone'] ?? null);
        $email = filled($person['email'] ?? null) ? mb_strtolower(trim((string) $person['email'])) : null;
        $name = trim((string) $person['full_name']);

        return (clone $query)->where(function ($inner) use ($digits, $email, $name): void {
            $inner->whereRaw('1 = 0');

            if ($digits !== null) {
                $inner->orWhere('phone_digits', $digits);
            }

            if ($email !== null) {
                $inner->orWhere('email', $email);
            }

            $inner->orWhere('full_name', $name);
        })->orderBy('id')->first();
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function fillContact(Contact $contact, array $person, bool $overwrite): void
    {
        $values = [
            'full_name' => trim((string) $person['full_name']),
            'greeting_name' => $this->text($person['greeting_name'] ?? null),
            'position' => $this->text($person['position'] ?? null),
            'email' => $this->text($person['email'] ?? null),
            'phone' => PhoneFormatter::format($this->text($person['phone'] ?? null)),
            'phone_extra' => PhoneFormatter::format($this->text($person['phone_extra'] ?? null)),
            'telegram' => $this->handle($person['telegram'] ?? null),
            'whatsapp' => PhoneFormatter::format($this->text($person['whatsapp'] ?? null)),
            'notes' => $this->personNotes($person),
        ];

        foreach ($values as $field => $value) {
            if ($value === null) {
                continue;
            }

            if ($overwrite || $contact->getAttribute($field) === null) {
                $contact->setAttribute($field, $value);
            } elseif ($field === 'notes' && ! str_contains((string) $contact->notes, $value)) {
                // Заметки не затираем, а дописываем: там могло быть то, чего в таблице нет.
                $contact->notes = rtrim((string) $contact->notes)."\n".$value;
            }
        }
    }

    /**
     * Скайп отдельной колонки не имеет — он уходит в заметки вместе с пометками
     * менеджера («по всем вопросам с ней»).
     *
     * @param  array<string, mixed>  $person
     */
    private function personNotes(array $person): ?string
    {
        $parts = [];

        if (filled($person['skype'] ?? null)) {
            $parts[] = 'Skype: '.trim((string) $person['skype']);
        }

        if (filled($person['notes'] ?? null)) {
            $parts[] = trim((string) $person['notes']);
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * Telegram: ссылка остаётся ссылкой, «@ник» — ником, номер — номером в едином виде.
     */
    private function handle(?string $raw): ?string
    {
        $raw = $this->text($raw);

        if ($raw === null) {
            return null;
        }

        if (preg_match('/^\+?[\d\s()\-]{9,}$/u', $raw) === 1) {
            return PhoneFormatter::format($raw);
        }

        return $raw;
    }

    private function link(Contact $contact, User|Company $subject, ContactRole $role, bool $primary, User $client, User $author, ManagerSheetReport $report): void
    {
        $link = ContactLink::query()->firstOrNew([
            'contact_id' => $contact->getKey(),
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'role' => $role->value,
        ]);

        if ($link->exists) {
            return;
        }

        $link->fill([
            'is_primary' => $primary,
            'client_user_id' => $client->getKey(),
            'source' => ContactSource::MANAGER_SHEET,
        ]);
        $link->created_by_user_id = $author->getKey();
        $link->save();

        $report->linksCreated++;
    }

    /**
     * Контрагент человека: ИП с его же фамилией среди юрлиц партнёра.
     *
     * Подсказка `company_hint` из таблицы («отгрузки на ИП Зольникова Валери»)
     * берётся первой; иначе владельца сличаем с названиями юрлиц по ядру имени.
     *
     * @param  array<string, mixed>  $person
     */
    private function companyFor(User $client, array $person, ContactRole $role): ?Company
    {
        $companies = Company::query()
            ->withoutGlobalScopes()
            ->where('user_id', $client->getKey())
            ->get(['id', 'name', 'legal_name']);

        if ($companies->isEmpty()) {
            return null;
        }

        $hint = $this->text($person['company_hint'] ?? null);

        if ($hint !== null) {
            $needle = mb_strtolower($hint);

            $found = $companies->first(fn (Company $company): bool => str_contains(mb_strtolower((string) $company->name), $needle)
                || str_contains(mb_strtolower((string) $company->legal_name), $needle));

            if ($found !== null) {
                return $found;
            }
        }

        if ($role !== ContactRole::OWNER) {
            return null;
        }

        $matches = $companies->filter(fn (Company $company): bool => $this->sameName($person, $company));

        // Ровно один — иначе не угадываем: у партнёра бывают два ИП на родню.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Человек и юрлицо носят одно имя: «Зольникова Валери» и «ИП Зольникова Валери».
     *
     * @param  array<string, mixed>  $person
     */
    private function sameName(array $person, Company $company): bool
    {
        $core = ClientNameIndex::core((string) $person['full_name']);

        return $core !== '' && (
            ClientNameIndex::core((string) $company->name) === $core
            || ClientNameIndex::core((string) $company->legal_name) === $core
        );
    }

    /**
     * Секция заметок партнёра из строки таблицы.
     *
     * @param  array<string, mixed>  $row
     */
    private function notesBlock(array $row, string $stamp): ?string
    {
        $lines = [];

        $old = $this->text($row['status'] ?? null);
        $new = $this->text($row['new_status'] ?? null);

        if ($old !== null || $new !== null) {
            $lines[] = '**Статус по таблице:** '.($old !== null && $new !== null && $old !== $new ? "{$old} → {$new}" : ($new ?? $old));
        }

        if (($payment = $this->text($row['payment'] ?? null)) !== null) {
            $lines[] = '**Условия оплаты:** '.$payment;
        }

        if (($docs = $this->text($row['docs'] ?? null)) !== null) {
            $lines[] = '**Документы (ЧЗ, ЭДО):** '.$docs;
        }

        $emails = array_values(array_filter(array_map('trim', (array) ($row['doc_emails'] ?? []))));

        if ($emails !== []) {
            $lines[] = '**Почты для документов:** '.implode(', ', $emails);
        }

        $channels = array_values(array_filter(array_map('trim', (array) ($row['channels'] ?? []))));

        if ($channels !== []) {
            $lines[] = '**Каналы связи:** '.implode('; ', $channels);
        }

        if (($comment = $this->text($row['comment'] ?? null)) !== null) {
            $lines[] = '**Особенности работы:** '.$comment;
        }

        if ($lines === []) {
            return null;
        }

        return self::NOTES_MARKER.' ('.$this->humanDate($stamp).")\n\n".implode("\n\n", $lines);
    }

    /**
     * Заменить ранее перенесённую секцию свежей — при `--overwrite`.
     */
    private function replaceSection(string $notes, string $block): string
    {
        $pattern = '/'.preg_quote(self::NOTES_MARKER, '/').'.*?(?=\n## |\z)/su';

        return (string) preg_replace($pattern, rtrim($block), $notes, 1);
    }

    private function paymentType(string $payment): ?PaymentType
    {
        $lower = mb_strtolower($payment);
        $deferred = str_contains($lower, 'отсрочк');
        $prepay = str_contains($lower, 'предоплат') || str_contains($lower, 'по счетам') || str_contains($lower, 'наличн');

        return match (true) {
            $deferred && $prepay => PaymentType::MIXED,
            $deferred => PaymentType::DEFERRED,
            $prepay => PaymentType::PREPAY,
            default => null,
        };
    }

    private function deferralDays(string $payment): ?int
    {
        return preg_match('/отсрочк\D{0,10}(\d{1,3})/ui', $payment, $match) === 1 ? (int) $match[1] : null;
    }

    private function humanDate(string $stamp): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($stamp)->format('d.m.Y');
        } catch (\Throwable) {
            return $stamp;
        }
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
