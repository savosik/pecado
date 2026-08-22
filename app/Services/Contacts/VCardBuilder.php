<?php

namespace App\Services\Contacts;

use App\Models\Company;
use App\Models\Contact;

/**
 * Сборка одной карточки vCard.
 *
 * Версия 3.0, не 4.0. Четвёртая красивее — нормальный KIND, честные URI, —
 * но встроенный импортёр Android на части прошивок читает её через раз, а
 * сценарий здесь ровно один: открыть файл на телефоне. Тройку понимают все.
 *
 * Каждая мелочь в форматировании ломает импорт, если её не соблюсти: CRLF
 * вместо переводов строк, фолдинг длинных строк на 75 октетов, экранирование
 * запятых и точек с запятой. Ошибка не видна глазами — телефон просто
 * отказывается открывать файл или молча теряет поле.
 */
class VCardBuilder
{
    /** Предел встраиваемого фото: больше телефон импортирует мучительно долго. */
    private const MAX_PHOTO_BYTES = 100 * 1024;

    /**
     * @param  bool  $withPhoto  вкладывать ли фото в base64
     */
    public function build(Contact $contact, bool $withPhoto = true): string
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];

        $lines[] = 'N:'.$this->structuredName($contact);
        $lines[] = 'FN:'.$this->escape($contact->full_name);

        if (filled($contact->greeting_name)) {
            $lines[] = 'NICKNAME:'.$this->escape($contact->greeting_name);
        }

        $organization = $this->organization($contact);

        if ($organization !== null) {
            $lines[] = 'ORG:'.$this->escape($organization);
        }

        if (filled($contact->position)) {
            $lines[] = 'TITLE:'.$this->escape($contact->position);
        }

        $role = $contact->links->first()?->role;

        if ($role !== null) {
            $lines[] = 'ROLE:'.$this->escape($role->label());
        }

        if (filled($contact->phone)) {
            $lines[] = 'TEL;TYPE=CELL,VOICE:'.$this->escape($contact->phone);
        }

        if (filled($contact->phone_extra)) {
            $lines[] = 'TEL;TYPE=WORK,VOICE:'.$this->escape($contact->phone_extra);
        }

        if (filled($contact->email)) {
            $lines[] = 'EMAIL;TYPE=INTERNET,WORK:'.$this->escape($contact->email);
        }

        $birthday = $this->birthday($contact);

        if ($birthday !== null) {
            $lines[] = 'BDAY:'.$birthday;
        }

        if (filled($contact->website)) {
            $lines[] = 'URL:'.$this->escape($contact->website);
        }

        foreach ($this->socialProfiles($contact) as $type => $url) {
            $lines[] = 'X-SOCIALPROFILE;TYPE='.$type.':'.$this->escape($url);
        }

        $note = $this->note($contact);

        if ($note !== null) {
            $lines[] = 'NOTE:'.$this->escape($note);
        }

        $lines[] = 'CATEGORIES:'.$this->escape($this->categories($contact));

        if ($withPhoto) {
            $photo = $this->photo($contact);

            if ($photo !== null) {
                $lines[] = 'PHOTO;ENCODING=b;TYPE=JPEG:'.$photo;
            }
        }

        // По этому идентификатору обратный импорт узнаёт свою карточку
        // и обновляет её, а не заводит дубль.
        $lines[] = 'UID:pecado-contact-'.$contact->getKey();
        $lines[] = 'REV:'.now()->utc()->format('Y-m-d\TH:i:s\Z');
        $lines[] = 'END:VCARD';

        return implode("\r\n", array_map([$this, 'fold'], $lines))."\r\n";
    }

    /**
     * Фамилия;Имя;Отчество — русский порядок, в котором ФИО и записывают.
     */
    private function structuredName(Contact $contact): string
    {
        $parts = preg_split('/\s+/u', trim((string) $contact->full_name)) ?: [];

        return implode(';', [
            $this->escape($parts[0] ?? ''),
            $this->escape($parts[1] ?? ''),
            $this->escape($parts[2] ?? ''),
            '',
            '',
        ]);
    }

    /**
     * Место работы: контрагент из первой привязки, иначе имя партнёра.
     */
    private function organization(Contact $contact): ?string
    {
        foreach ($contact->links as $link) {
            if ($link->subject instanceof Company) {
                return (string) ($link->subject->name ?: $link->subject->legal_name);
            }
        }

        return $contact->client === null ? null : (string) $contact->client->display_name;
    }

    /**
     * День рождения. Без известного года — формат `--MMDD`: телефон кладёт
     * такую дату в календарь и не сочиняет возраст.
     */
    private function birthday(Contact $contact): ?string
    {
        if ($contact->birthday === null) {
            return null;
        }

        return $contact->birthday_has_year
            ? $contact->birthday->format('Y-m-d')
            : $contact->birthday->format('--md');
    }

    /**
     * @return array<string, string>
     */
    private function socialProfiles(Contact $contact): array
    {
        $profiles = [];

        if (filled($contact->telegram)) {
            $profiles['telegram'] = $this->socialUrl($contact->telegram, 'https://t.me/');
        }

        if (filled($contact->instagram)) {
            $profiles['instagram'] = $this->socialUrl($contact->instagram, 'https://instagram.com/');
        }

        return $profiles;
    }

    private function socialUrl(string $value, string $base): string
    {
        $value = trim($value);

        if (str_starts_with($value, 'http')) {
            return $value;
        }

        return $base.ltrim($value, '@/');
    }

    private function note(Contact $contact): ?string
    {
        $parts = [];

        if ($contact->preferred_channel !== null) {
            $parts[] = 'Предпочитает: '.$contact->preferred_channel->label();
        }

        if ($contact->client !== null) {
            $parts[] = 'Партнёр: '.$contact->client->display_name;
        }

        if (filled($contact->whatsapp)) {
            $parts[] = 'WhatsApp: '.$contact->whatsapp;
        }

        return $parts === [] ? null : implode('. ', $parts);
    }

    private function categories(Contact $contact): string
    {
        $categories = ['Pecado'];

        foreach ($contact->links as $link) {
            $categories[] = $link->role->label();
        }

        return implode(',', array_values(array_unique($categories)));
    }

    private function photo(Contact $contact): ?string
    {
        $media = $contact->getFirstMedia(Contact::AVATAR_COLLECTION);

        if ($media === null) {
            return null;
        }

        try {
            $path = $media->hasGeneratedConversion(Contact::AVATAR_VCARD_CONVERSION)
                ? $media->getPath(Contact::AVATAR_VCARD_CONVERSION)
                : $media->getPath();

            if (! is_file($path) || filesize($path) > self::MAX_PHOTO_BYTES) {
                return null;
            }

            $binary = file_get_contents($path);

            return $binary === false ? null : base64_encode($binary);
        } catch (\Throwable) {
            // Фото — украшение карточки. Недоступный файл не повод не отдать
            // человеку его телефон.
            return null;
        }
    }

    /**
     * Экранирование по RFC 2426: запятая, точка с запятой, обратный слэш
     * и переводы строк внутри значения.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ',', ';'],
            ['\\\\', '\\n', '\\n', '\\n', '\\,', '\;'],
            trim($value),
        );
    }

    /**
     * Фолдинг длинных строк: 75 октетов, продолжение с пробела.
     *
     * Считаем именно октеты, а не символы: кириллица в UTF-8 занимает два байта,
     * и разрез посреди символа даст на телефоне кашу.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = [];
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($chunks === [] ? 75 : 74)) {
                $chunks[] = $current;
                $current = '';
            }

            $current .= $char;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $first = array_shift($chunks);

        return $first.($chunks === [] ? '' : "\r\n ".implode("\r\n ", $chunks));
    }
}
