<?php

namespace App\Services\Contacts;

/**
 * Разбор контактного лица, записанного в анкете одной строкой.
 *
 * В анкете партнёра люди лежат так: «Афонина Мария, +7 912 345-67-89,
 * buh@romashka.ru». Ни выгрузить в телефон, ни подшить письмо к такому нельзя —
 * поэтому строку разбираем на поля один раз, при переносе в справочник.
 *
 * Отличие от прошлого захода: строка **без почты тоже переносится**. Тогда
 * контакт без адреса был бесполезен для рассылки; теперь это нормальная карточка
 * телефонной книги, и терять имя с телефоном незачем.
 */
class ProfileContactParser
{
    /**
     * @return array{full_name: string, email: ?string, phone: ?string, position: ?string}|null
     */
    public function parse(?string $name, ?string $contact, ?string $position = null): ?array
    {
        $haystack = trim(($name ?? '').' '.($contact ?? ''));

        if ($haystack === '') {
            return null;
        }

        $email = null;

        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $haystack, $match)) {
            $email = mb_strtolower(trim($match[0], ".,;: \t"));
        }

        $phone = null;

        if (preg_match('/\+?\d[\d\s()\-]{9,}\d/u', $haystack, $match)) {
            $phone = trim($match[0]);
        }

        $fullName = $this->fullName($name, $contact, $email, $phone);

        // Ни имени, ни способа связи — переносить нечего.
        if ($fullName === '' && $email === null && $phone === null) {
            return null;
        }

        return [
            'full_name' => $fullName !== '' ? $fullName : ($email ?? $phone ?? 'Без имени'),
            'email' => $email,
            'phone' => $phone,
            'position' => filled($position) ? trim($position) : null,
        ];
    }

    /**
     * Имя: сначала поле имени, иначе — то, что осталось от строки контакта
     * после вычитания почты и телефона, иначе часть адреса до собаки.
     */
    private function fullName(?string $name, ?string $contact, ?string $email, ?string $phone): string
    {
        $fullName = trim((string) $name);

        if ($fullName !== '') {
            return $fullName;
        }

        $rest = (string) $contact;

        foreach (array_filter([$email, $phone]) as $known) {
            $rest = str_ireplace($known, '', $rest);
        }

        $rest = trim($rest, " \t,;.:-—()");

        if ($rest !== '' && ! preg_match('/^[\d\s+()\-]+$/u', $rest)) {
            return $rest;
        }

        if ($email !== null) {
            return ucfirst(explode('@', $email)[0]);
        }

        return '';
    }
}
