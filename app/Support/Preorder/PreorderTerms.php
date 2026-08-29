<?php

namespace App\Support\Preorder;

/**
 * Условия предзаказа одним словом для всех поверхностей: карточка товара,
 * корзина, чекаут, кабинет, письма.
 *
 * Цифра берётся из config('preorder.lead_days'); текст собирается здесь,
 * чтобы «7–9 дней» нигде не было захардкожено и не разъехалось.
 */
final class PreorderTerms
{
    /**
     * @return array{min: int, max: int}
     */
    public static function leadDays(): array
    {
        $min = max(1, (int) config('preorder.lead_days.min', 7));
        $max = max($min, (int) config('preorder.lead_days.max', 9));

        return ['min' => $min, 'max' => $max];
    }

    /**
     * «7–9 дней» / «7 дней» — для подписей и бейджей.
     */
    public static function leadLabel(): string
    {
        ['min' => $min, 'max' => $max] = self::leadDays();

        $days = $min === $max ? (string) $min : $min.'–'.$max;

        return $days.' '.self::pluralDays($max);
    }

    /**
     * Полная фраза для подсказок: «поставка 7–9 дней».
     */
    public static function leadSentence(): string
    {
        return 'поставка '.self::leadLabel();
    }

    /**
     * Payload для фронта (shared-проп Inertia `preorder`).
     *
     * @return array{lead_min: int, lead_max: int, lead_label: string}
     */
    public static function payload(): array
    {
        ['min' => $min, 'max' => $max] = self::leadDays();

        return [
            'lead_min' => $min,
            'lead_max' => $max,
            'lead_label' => self::leadLabel(),
        ];
    }

    private static function pluralDays(int $n): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;

        if ($n > 10 && $n < 20) {
            return 'дней';
        }
        if ($n1 > 1 && $n1 < 5) {
            return 'дня';
        }
        if ($n1 === 1) {
            return 'день';
        }

        return 'дней';
    }
}
