<?php

namespace App\Services\Erp\Handlers;

use App\Enums\Country;
use Illuminate\Support\Facades\Log;

/**
 * Общий хелпер для нормализации страны из 1С в Country enum.
 */
trait NormalizesCountry
{
    /**
     * Нормализует строковое значение страны из 1С в ISO-код (Country enum value) или null.
     * 1С может слать: "РОССИЯ", "Россия", "КАЗАХСТАН", "RU" и т.д.
     * Неизвестные страны → null + warning в лог.
     */
    private function normalizeCountry(?string $value, ?string $default = null): ?string
    {
        if (! $value) {
            return $default;
        }

        $map = [
            // Россия
            'россия' => Country::RU->value,
            'russia' => Country::RU->value,
            'ru' => Country::RU->value,
            // Беларусь
            'беларусь' => Country::BY->value,
            'белоруссия' => Country::BY->value,
            'belarus' => Country::BY->value,
            'by' => Country::BY->value,
            // Казахстан
            'казахстан' => Country::KZ->value,
            'kazakhstan' => Country::KZ->value,
            'kz' => Country::KZ->value,
            // Украина
            'украина' => Country::UA->value,
            'ukraine' => Country::UA->value,
            'ua' => Country::UA->value,
            // Узбекистан
            'узбекистан' => Country::UZ->value,
            'uzbekistan' => Country::UZ->value,
            'uz' => Country::UZ->value,
            // Азербайджан
            'азербайджан' => Country::AZ->value,
            'azerbaijan' => Country::AZ->value,
            'az' => Country::AZ->value,
            // Армения
            'армения' => Country::AM->value,
            'armenia' => Country::AM->value,
            'am' => Country::AM->value,
            // Грузия
            'грузия' => Country::GE->value,
            'georgia' => Country::GE->value,
            'ge' => Country::GE->value,
            // Кыргызстан
            'кыргызстан' => Country::KG->value,
            'киргизия' => Country::KG->value,
            'kyrgyzstan' => Country::KG->value,
            'kg' => Country::KG->value,
            // Молдова
            'молдова' => Country::MD->value,
            'молдавия' => Country::MD->value,
            'moldova' => Country::MD->value,
            'md' => Country::MD->value,
            // Таджикистан
            'таджикистан' => Country::TJ->value,
            'tajikistan' => Country::TJ->value,
            'tj' => Country::TJ->value,
            // Туркменистан
            'туркменистан' => Country::TM->value,
            'turkmenistan' => Country::TM->value,
            'tm' => Country::TM->value,
        ];

        $normalized = $map[mb_strtolower(trim($value))] ?? null;

        if (! $normalized) {
            Log::warning('ERP: неизвестная страна, пропускаем', ['country' => $value]);
        }

        return $normalized ?? $default;
    }
}
