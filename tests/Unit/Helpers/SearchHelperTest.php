<?php

namespace Tests\Unit\Helpers;

use App\Helpers\SearchHelper;
use PHPUnit\Framework\TestCase;

class SearchHelperTest extends TestCase
{
    /**
     * Тест транслитерации RU → EN.
     */
    public function test_transliterate_cyrillic_to_latin(): void
    {
        $this->assertEquals('vibrator', SearchHelper::transliterate('Вибратор'));
        $this->assertEquals('smazka', SearchHelper::transliterate('Смазка'));
        $this->assertEquals('prezervativ', SearchHelper::transliterate('Презерватив'));
    }

    /**
     * Тест: транслитерация смешанного текста (кириллица + латиница).
     */
    public function test_transliterate_mixed_text(): void
    {
        $result = SearchHelper::transliterate('Вибратор Lola Dream');
        $this->assertEquals('vibrator lola dream', $result);
    }

    /**
     * Тест: транслитерация пустой строки.
     */
    public function test_transliterate_empty_string(): void
    {
        $this->assertEquals('', SearchHelper::transliterate(''));
    }

    /**
     * Тест: обратная транслитерация EN → RU.
     */
    public function test_transliterate_to_cyrillic(): void
    {
        $this->assertEquals('вибратор', SearchHelper::transliterateToCyrillic('Vibrator'));
        $this->assertEquals('смазка', SearchHelper::transliterateToCyrillic('Smazka'));
    }

    /**
     * Тест: обратная транслитерация — многосимвольные сочетания.
     */
    public function test_transliterate_to_cyrillic_multi_char(): void
    {
        // shch → щ, sh → ш, ch → ч, zh → ж
        $this->assertEquals('щотка', SearchHelper::transliterateToCyrillic('Shchotka'));
        $this->assertEquals('шуба', SearchHelper::transliterateToCyrillic('Shuba'));
    }

    /**
     * Тест: конвертация раскладки RU → EN.
     */
    public function test_convert_layout_cyrillic_to_latin(): void
    {
        $result = SearchHelper::convertLayout('Вибратор');
        $this->assertEquals('db,hfnjh', $result);
    }

    /**
     * Тест: конвертация раскладки EN → RU.
     */
    public function test_convert_layout_latin_to_cyrillic(): void
    {
        $result = SearchHelper::convertLayout('Lola');
        $this->assertEquals('дщдф', $result);
    }

    /**
     * Тест: конвертация пустой строки.
     */
    public function test_convert_layout_empty_string(): void
    {
        $this->assertEquals('', SearchHelper::convertLayout(''));
    }
}
