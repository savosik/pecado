<?php

namespace App\Helpers;

class SearchHelper
{
    /**
     * Таблица транслитерации: кириллица → латиница.
     */
    private static array $translitMap = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /**
     * Таблица обратной транслитерации: латиница → кириллица.
     * Многосимвольные сочетания идут первыми (порядок важен).
     */
    private static array $cyrillicMultiMap = [
        'shch' => 'щ',
        'sh' => 'ш',
        'ch' => 'ч',
        'zh' => 'ж',
        'yo' => 'ё',
        'yu' => 'ю',
        'ya' => 'я',
        'kh' => 'х',
        'ts' => 'ц',
    ];

    private static array $cyrillicSingleMap = [
        'a' => 'а', 'b' => 'б', 'v' => 'в', 'g' => 'г', 'd' => 'д',
        'e' => 'е', 'z' => 'з', 'i' => 'и', 'y' => 'й', 'k' => 'к',
        'l' => 'л', 'm' => 'м', 'n' => 'н', 'o' => 'о', 'p' => 'п',
        'r' => 'р', 's' => 'с', 't' => 'т', 'u' => 'у', 'f' => 'ф',
    ];

    /**
     * Таблица конвертации раскладки: физическое расположение клавиш.
     * RU ↔ EN
     */
    private static array $layoutMap = [
        'й' => 'q', 'ц' => 'w', 'у' => 'e', 'к' => 'r', 'е' => 't',
        'н' => 'y', 'г' => 'u', 'ш' => 'i', 'щ' => 'o', 'з' => 'p',
        'х' => '[', 'ъ' => ']', 'ф' => 'a', 'ы' => 's', 'в' => 'd',
        'а' => 'f', 'п' => 'g', 'р' => 'h', 'о' => 'j', 'л' => 'k',
        'д' => 'l', 'ж' => ';', 'э' => "'", 'я' => 'z', 'ч' => 'x',
        'с' => 'c', 'м' => 'v', 'и' => 'b', 'т' => 'n', 'ь' => 'm',
        'б' => ',', 'ю' => '.', 'ё' => '`',
    ];

    /**
     * Транслитерация кириллицы в латиницу (RU → EN).
     *
     * «Вибратор Lola» → «Vibrator Lola»
     */
    public static function transliterate(string $text): string
    {
        $result = '';
        $text = mb_strtolower($text);
        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1);

            if (isset(self::$translitMap[$char])) {
                $result .= self::$translitMap[$char];
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Обратная транслитерация латиницы в кириллицу (EN → RU).
     *
     * «Vibrator Lola» → «вибратор лола»
     *
     * Сначала обрабатываются многосимвольные сочетания (shch→щ, sh→ш, ch→ч, zh→ж),
     * затем односимвольные.
     */
    public static function transliterateToCyrillic(string $text): string
    {
        $text = mb_strtolower($text);

        // Сначала заменяем многосимвольные сочетания
        foreach (self::$cyrillicMultiMap as $latin => $cyrillic) {
            $text = str_replace($latin, $cyrillic, $text);
        }

        // Затем односимвольные
        $result = '';
        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1);

            if (isset(self::$cyrillicSingleMap[$char])) {
                $result .= self::$cyrillicSingleMap[$char];
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Конвертация раскладки клавиатуры.
     *
     * Определяет наличие кириллических символов и заменяет каждый символ
     * на его пару по физическому расположению клавиши.
     *
     * «Вибратор» → «db,hfnjh» (RU→EN)
     * «Lola» → «дщдф» (EN→RU)
     */
    public static function convertLayout(string $text): string
    {
        $lowerText = mb_strtolower($text);
        $hasCyrillic = (bool) preg_match('/[а-яёА-ЯЁ]/u', $text);

        if ($hasCyrillic) {
            // RU → EN: кириллица → соответствующая латинская клавиша
            $map = self::$layoutMap;
        } else {
            // EN → RU: латиница → соответствующая кириллическая клавиша
            $map = array_flip(self::$layoutMap);
        }

        $result = '';
        $len = mb_strlen($lowerText);

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($lowerText, $i, 1);

            if (isset($map[$char])) {
                $result .= $map[$char];
            } else {
                $result .= $char;
            }
        }

        return $result;
    }
}
