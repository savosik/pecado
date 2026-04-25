<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TaxId implements ValidationRule
{
    public function __construct(private readonly ?string $country) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('ИНН обязателен.');

            return;
        }

        $normalized = trim($value);

        match (strtoupper((string) $this->country)) {
            'RU' => $this->validateRu($normalized, $fail),
            'BY' => $this->validateBy($normalized, $fail),
            'KZ' => $this->validateKz($normalized, $fail),
            default => $this->validateGeneric($normalized, $fail),
        };
    }

    private function validateRu(string $value, Closure $fail): void
    {
        if (! preg_match('/^\d{10}$|^\d{12}$/', $value)) {
            $fail('ИНН России должен содержать 10 цифр (юрлицо) или 12 цифр (ИП/физлицо).');

            return;
        }

        $digits = array_map('intval', str_split($value));

        if (strlen($value) === 10) {
            $weights = [2, 4, 10, 3, 5, 9, 4, 6, 8];
            $checksum = 0;
            for ($i = 0; $i < 9; $i++) {
                $checksum += $weights[$i] * $digits[$i];
            }
            if ($checksum % 11 % 10 !== $digits[9]) {
                $fail('Некорректная контрольная сумма ИНН России.');
            }

            return;
        }

        $weights1 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
        $weights2 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];

        $sum1 = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum1 += $weights1[$i] * $digits[$i];
        }

        $sum2 = 0;
        for ($i = 0; $i < 11; $i++) {
            $sum2 += $weights2[$i] * $digits[$i];
        }

        if ($sum1 % 11 % 10 !== $digits[10] || $sum2 % 11 % 10 !== $digits[11]) {
            $fail('Некорректная контрольная сумма ИНН России.');
        }
    }

    private function validateBy(string $value, Closure $fail): void
    {
        if (! preg_match('/^\d{9}$/', $value)) {
            $fail('УНП Беларуси должен содержать 9 цифр.');
        }
    }

    private function validateKz(string $value, Closure $fail): void
    {
        if (! preg_match('/^\d{12}$/', $value)) {
            $fail('БИН/ИИН Казахстана должен содержать 12 цифр.');

            return;
        }

        $digits = array_map('intval', str_split($value));

        $weights1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
        $sum = 0;
        for ($i = 0; $i < 11; $i++) {
            $sum += $weights1[$i] * $digits[$i];
        }
        $check = $sum % 11;

        if ($check === 10) {
            $weights2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2];
            $sum = 0;
            for ($i = 0; $i < 11; $i++) {
                $sum += $weights2[$i] * $digits[$i];
            }
            $check = $sum % 11;

            if ($check === 10) {
                $fail('Некорректная контрольная сумма БИН/ИИН Казахстана.');

                return;
            }
        }

        if ($check !== $digits[11]) {
            $fail('Некорректная контрольная сумма БИН/ИИН Казахстана.');
        }
    }

    private function validateGeneric(string $value, Closure $fail): void
    {
        if (! preg_match('/^[A-Za-z0-9\-]{4,32}$/', $value)) {
            $fail('Введите корректный ИНН/налоговый номер.');
        }
    }
}
