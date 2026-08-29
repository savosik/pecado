<?php

namespace App\Services\Contacts;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\User;
use App\Support\Contacts\ContractorSheetReport;
use App\Support\Crm\ClientNameIndex;
use App\Support\PhoneFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Перенос таблицы «контрагенты партнёра — почты» в справочник людей.
 *
 * У крупного партнёра-сети юрлиц десятки: у «Гевеи» их 64, и почти каждое —
 * ИП франчайзи, чьё название в 1С **и есть** ФИО владельца. Менеджер ведёт
 * такую сеть таблицей «ИП — тип работы — личная почта — почта для счетов»,
 * и это единственное место, где записано, какой ящик чей.
 *
 * Отличие от [[ManagerSheetImporter]]: там строка — партнёр, здесь строка —
 * его контрагент. Поэтому человек привязывается к юрлицу (собственник)
 * и к самому партнёру (контактное лицо): в карточке ИП менеджер ищет владельца,
 * а в карточке партнёра — всех людей сети разом.
 *
 * Чего импортёр намеренно не делает:
 *
 *  - **не выдумывает людей за общими ящиками.** Адрес, встречающийся личной
 *    почтой у двух и более контрагентов, — ящик сети («открытие франшизы»),
 *    и человека за ним нет. Такие адреса складываются в заметку, но карточку
 *    не получают: правило приёмки от 27.08 — безымянных в справочник не заводить;
 *  - **не приписывает человеку общий телефон.** Номер, стоящий у нескольких
 *    юрлиц партнёра, принадлежит сети, а не владельцу ИП;
 *  - **не достраивает ФИО за ООО.** «Никифоров ИО ООО» — это фирма, и отчество
 *    её директора не выводится из названия.
 */
class ContractorSheetImporter
{
    /** Метка секции в заметках контакта: по ней повторный прогон узнаёт свой текст. */
    public const NOTES_MARKER = '## Из таблицы контрагентов';

    public function __construct(private readonly PersonNameParser $names) {}

    /**
     * @param  array<string, mixed>  $document  разобранная таблица: client_id + rows[]
     */
    public function import(array $document, User $author, bool $dryRun = false, bool $overwrite = false): ContractorSheetReport
    {
        $report = new ContractorSheetReport;

        $client = User::query()->findOrFail((int) ($document['client_id'] ?? 0));
        $rows = is_array($document['rows'] ?? null) ? $document['rows'] : [];
        $stamp = $this->humanDate((string) ($document['exported_at'] ?? now()->toDateString()));
        $title = trim((string) ($document['source_title'] ?? 'таблицы контрагентов'));

        $companies = $this->companiesOf($client);
        $shared = $this->sharedEmails($rows, $client, (array) ($document['shared_emails'] ?? []));
        $sharedPhones = $this->sharedPhones($companies);

        $report->sharedEmails = array_values($shared);

        foreach ($rows as $row) {
            $report->rowsTotal++;

            $line = (int) ($row['line'] ?? 0);
            $name = trim((string) ($row['contractor'] ?? ''));

            $matches = $this->matchCompany($companies, $row);

            if ($matches === []) {
                $report->unmatched[] = ['line' => $line, 'contractor' => $name];

                continue;
            }

            if (count($matches) > 1) {
                $report->ambiguous[] = ['line' => $line, 'contractor' => $name, 'candidates' => count($matches)];

                continue;
            }

            $company = $matches[0];
            $person = $this->person($row, $company, $shared, $sharedPhones);

            if ($person === null) {
                $report->withoutPerson[] = ['line' => $line, 'contractor' => $name];

                continue;
            }

            $report->rowsMatched++;

            if ($dryRun) {
                $this->preview($client, $person, $report);

                continue;
            }

            DB::transaction(fn () => $this->apply($row, $client, $company, $person, $author, $stamp, $title, $overwrite, $report));
        }

        return $report;
    }

    /**
     * Юрлица партнёра — со снятыми глобальными фильтрами: импорт идёт из консоли,
     * где текущего пользователя нет, и видимость решать нечем.
     *
     * @return list<Company>
     */
    private function companiesOf(User $client): array
    {
        return Company::query()
            ->withoutGlobalScopes()
            ->where('user_id', $client->getKey())
            ->get(['id', 'user_id', 'name', 'legal_name', 'email', 'phone'])
            ->all();
    }

    /**
     * Контрагент строки среди юрлиц партнёра.
     *
     * Явный `company_id` из файла — первым: названия в таблице и в 1С расходятся
     * пометками («ЗАКРЫТО», «зактыт»), и спорную строку сверяет человек.
     *
     * @param  list<Company>  $companies
     * @param  array<string, mixed>  $row
     * @return list<Company>
     */
    private function matchCompany(array $companies, array $row): array
    {
        if (filled($row['company_id'] ?? null)) {
            $id = (int) $row['company_id'];

            return array_values(array_filter($companies, fn (Company $company): bool => (int) $company->getKey() === $id));
        }

        $key = $this->matchKey((string) ($row['contractor'] ?? ''));

        if ($key === '') {
            return [];
        }

        return array_values(array_filter(
            $companies,
            fn (Company $company): bool => $this->matchKey((string) $company->name) === $key
                || $this->matchKey((string) $company->legal_name) === $key,
        ));
    }

    /**
     * Ключ сличения названий: имя без города, формы и пометки о закрытии.
     *
     * «ИП Галецкая Елена Олеговна (закрыт)» и «Галецкая Елена Олеговна ИП,
     * д. Сапроново» — одно юрлицо. Скобки убираются вместе с содержимым:
     * `ClientNameIndex::core` оставляет от них слово «закрыт», и строки
     * перестают совпадать.
     */
    private function matchKey(string $name): string
    {
        $value = (string) preg_replace('/\([^)]*\)/u', ' ', $name);
        $value = ClientNameIndex::core($value);
        $value = (string) preg_replace('/\b(?:закрыт|закрыто|зактыт|закрыта|не\s+работает)\b/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Ящики сети, за которыми нет одного человека.
     *
     * Считаются по самой таблице: адрес, записанный личной почтой у двух
     * и более контрагентов, личным быть не может. Плюс почта самого партнёра
     * и адреса, названные общими в файле явно.
     *
     * @param  array<int, mixed>  $rows
     * @param  array<int, mixed>  $declared
     * @return array<string, string>
     */
    private function sharedEmails(array $rows, User $client, array $declared): array
    {
        $seen = [];

        foreach ($rows as $row) {
            foreach (array_unique($this->emails($row['personal_emails'] ?? [])) as $email) {
                $seen[$email] = ($seen[$email] ?? 0) + 1;
            }
        }

        $shared = [];

        foreach ($seen as $email => $count) {
            if ($count > 1) {
                $shared[$email] = $email;
            }
        }

        foreach ($this->emails($declared) as $email) {
            $shared[$email] = $email;
        }

        if (filled($client->email)) {
            $email = mb_strtolower(trim((string) $client->email));
            $shared[$email] = $email;
        }

        return $shared;
    }

    /**
     * Телефоны, стоящие у нескольких юрлиц партнёра, — номера сети, не людей.
     *
     * @param  list<Company>  $companies
     * @return array<string, true>
     */
    private function sharedPhones(array $companies): array
    {
        $seen = [];

        foreach ($companies as $company) {
            $digits = Contact::digitsOf($company->phone);

            if ($digits !== null) {
                $seen[$digits] = ($seen[$digits] ?? 0) + 1;
            }
        }

        return array_map(fn (): bool => true, array_filter($seen, fn (int $count): bool => $count > 1));
    }

    /**
     * Человек строки: явно названный в файле или выведенный из названия ИП.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $shared
     * @param  array<string, true>  $sharedPhones
     * @return array<string, mixed>|null
     */
    private function person(array $row, Company $company, array $shared, array $sharedPhones): ?array
    {
        $declared = is_array($row['person'] ?? null) ? $row['person'] : [];

        $fullName = $this->text($declared['full_name'] ?? null)
            ?? $this->names->parse((string) ($company->name ?: $row['contractor'] ?? ''));

        if (blank($fullName)) {
            return null;
        }

        $email = $this->text($declared['email'] ?? null);

        if ($email === null) {
            foreach ($this->emails($row['personal_emails'] ?? []) as $candidate) {
                if (! isset($shared[$candidate])) {
                    $email = $candidate;

                    break;
                }
            }
        }

        $phone = $this->text($declared['phone'] ?? null);

        if ($phone === null) {
            $digits = Contact::digitsOf($company->phone);

            if ($digits !== null && ! isset($sharedPhones[$digits])) {
                $phone = (string) $company->phone;
            }
        }

        return [
            'full_name' => $fullName,
            'role' => ContactRole::tryFrom((string) ($declared['role'] ?? '')) ?? ContactRole::OWNER,
            'email' => $email === null ? null : mb_strtolower($email),
            'phone' => PhoneFormatter::format($phone),
            'position' => $this->text($declared['position'] ?? null),
        ];
    }

    /**
     * В пробном прогоне считаем то же, что записали бы: отчёт должен совпасть
     * с боевым, а не показать нули из-за раннего выхода.
     *
     * @param  array<string, mixed>  $person
     */
    private function preview(User $client, array $person, ContractorSheetReport $report): void
    {
        if ($this->findExisting($client, $person) === null) {
            $report->contactsCreated++;
            $report->linksCreated += 2; // юрлицо и партнёр
        } else {
            $report->contactsUpdated++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $person
     */
    private function apply(array $row, User $client, Company $company, array $person, User $author, string $stamp, string $title, bool $overwrite, ContractorSheetReport $report): void
    {
        $contact = $this->findExisting($client, $person);
        $isNew = $contact === null;

        $contact ??= new Contact;

        foreach (['full_name' => $person['full_name'], 'email' => $person['email'], 'phone' => $person['phone'], 'position' => $person['position']] as $field => $value) {
            if ($value === null) {
                continue;
            }

            if ($overwrite || $isNew || $contact->getAttribute($field) === null) {
                $contact->setAttribute($field, $value);
            }
        }

        $contact->notes = $this->mergeNotes((string) $contact->notes, $this->notesBlock($row, $company, $stamp, $title), $overwrite);

        if ($isNew) {
            $contact->client_user_id = $client->getKey();
            $contact->source = ContactSource::MANAGER_SHEET;
            $contact->created_by_user_id = $author->getKey();
        }

        // Закрытое юрлицо — повод не писать этому человеку, а не повод забыть его:
        // карточка остаётся, но гаснет. Активность живого не трогаем при повторе.
        if ($isNew || $overwrite) {
            $contact->is_active = ! $this->looksClosed($row, $company);
        }

        $contact->updated_by_user_id = $author->getKey();
        $contact->save();

        $isNew ? $report->contactsCreated++ : $report->contactsUpdated++;

        $this->link($contact, $company, $person['role'], $client, $author, $report);
        // У партнёра человек — контактное лицо его юрлица: собственником сети
        // владелец отдельного ИП не является.
        $this->link($contact, $client, ContactRole::MANAGER, $client, $author, $report);
    }

    /**
     * Тот же человек у того же партнёра: по почте, телефону или имени.
     *
     * @param  array<string, mixed>  $person
     */
    private function findExisting(User $client, array $person): ?Contact
    {
        $digits = Contact::digitsOf($person['phone'] ?? null);
        $email = $person['email'] ?? null;
        $name = trim((string) $person['full_name']);

        return Contact::query()
            ->where('client_user_id', $client->getKey())
            ->whereNull('merged_into_id')
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

    private function link(Contact $contact, User|Company $subject, ContactRole $role, User $client, User $author, ContractorSheetReport $report): void
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
            'is_primary' => false,
            'client_user_id' => $client->getKey(),
            'source' => ContactSource::MANAGER_SHEET,
        ]);
        $link->created_by_user_id = $author->getKey();
        $link->save();

        $report->linksCreated++;
    }

    /**
     * Что таблица знает о контрагенте — заметкой в карточке человека.
     *
     * Общие ящики («на что выставлять счёт», «с кем связываться») сюда и уходят:
     * знание нужное, но карточки человека не заслуживает.
     *
     * @param  array<string, mixed>  $row
     */
    private function notesBlock(array $row, Company $company, string $stamp, string $title): string
    {
        $lines = ['**Контрагент:** '.trim((string) ($company->name ?: $row['contractor'] ?? ''))];

        if (($work = $this->text($row['work_type'] ?? null)) !== null) {
            $lines[] = '**Тип работы:** '.$work;
        }

        if (($status = $this->text($row['status'] ?? null)) !== null) {
            $lines[] = '**Пометка:** '.$status;
        }

        foreach ([
            'invoice_emails' => '**Почта для счетов:** ',
            'responsible_emails' => '**Почта ответственного за ИП:** ',
        ] as $field => $label) {
            $emails = $this->emails($row[$field] ?? []);

            if ($emails !== []) {
                $lines[] = $label.implode(', ', $emails);
            }
        }

        if (($note = $this->text($row['note'] ?? null)) !== null) {
            $lines[] = '**Примечание:** '.$note;
        }

        return self::NOTES_MARKER.' ('.$title.', '.$stamp.")\n\n".implode("\n", $lines);
    }

    /**
     * Заметка дописывается один раз: метка секции защищает от дублей при повторе.
     */
    private function mergeNotes(string $existing, string $block, bool $overwrite): string
    {
        $existing = trim($existing);

        if ($existing === '') {
            return $block;
        }

        if (! str_contains($existing, self::NOTES_MARKER)) {
            return $existing."\n\n".$block;
        }

        if (! $overwrite) {
            return $existing;
        }

        $pattern = '/'.preg_quote(self::NOTES_MARKER, '/').'.*?(?=\n## |\z)/su';

        return trim((string) preg_replace($pattern, rtrim($block), $existing, 1));
    }

    /**
     * Работа с контрагентом прекращена — по типу работы, пометке или названию в 1С.
     *
     * @param  array<string, mixed>  $row
     */
    private function looksClosed(array $row, Company $company): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) ($row['work_type'] ?? ''),
            (string) ($row['status'] ?? ''),
            (string) $company->name,
        ])));

        foreach (['закрыт', 'зактыт', 'не работает', 'банкрот'] as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Адреса из ячейки: в таблице они идут списком, вперемешку с пояснениями
     * («И добавить в копию …», «ТГ: @…»).
     *
     * @return list<string>
     */
    private function emails(mixed $value): array
    {
        $text = is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value;

        preg_match_all('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $text, $matches);

        return array_values(array_unique(array_map(
            static fn (string $email): string => mb_strtolower(rtrim($email, '.,;')),
            $matches[0],
        )));
    }

    private function humanDate(string $stamp): string
    {
        try {
            return Carbon::parse($stamp)->format('d.m.Y');
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
