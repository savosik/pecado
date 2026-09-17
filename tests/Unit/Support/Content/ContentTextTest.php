<?php

namespace Tests\Unit\Support\Content;

use App\Support\Content\ContentText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ContentTextTest extends TestCase
{
    #[Test]
    #[TestDox('Editor.js: заголовки, абзацы, вложенные списки, таблица; самописный блок разбирается без ссылок')]
    public function editor_js_blocks_become_structured_text(): void
    {
        $json = json_encode(['blocks' => [
            ['type' => 'header', 'data' => ['text' => 'Доставка', 'level' => 2]],
            ['type' => 'paragraph', 'data' => ['text' => 'Отгружаем <b>ежедневно</b>&nbsp;до 15:00.']],
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => [
                ['content' => 'Москва', 'items' => [['content' => 'курьер', 'items' => []]]],
                'Регионы',
            ]]],
            ['type' => 'table', 'data' => ['content' => [['Сумма', 'Доставка'], ['от 30 000 ₽', 'бесплатно']]]],
            ['type' => 'alertBanner', 'data' => ['title' => 'Внимание', 'message' => 'Праздничный график', 'url' => 'https://pecado.ru/x', 'variant' => 'warning']],
            ['type' => 'image', 'data' => ['file' => ['url' => '/storage/a.jpg'], 'caption' => 'Склад']],
            ['type' => 'delimiter', 'data' => []],
        ]]);

        $this->assertSame(
            "## Доставка\n\nОтгружаем ежедневно до 15:00.\n\n- Москва\n  - курьер\n- Регионы\n\n"
            ."| Сумма | Доставка |\n| от 30 000 ₽ | бесплатно |\n\nВнимание\nПраздничный график\n\nСклад",
            ContentText::toText($json),
        );
    }

    #[Test]
    #[TestDox('HTML: блоки — переносы, заголовки и пункты — разметка, скрипты и сущности убраны; пусто — пустая строка')]
    public function html_becomes_text(): void
    {
        $html = '<h2>Оплата</h2><p>Безналичный&nbsp;расчёт.<br>По счёту.</p><ul><li>Карта</li><li>Счёт</li></ul><script>alert(1)</script>';

        $this->assertSame("## Оплата\n\nБезналичный расчёт.\nПо счёту.\n\n- Карта\n- Счёт", ContentText::toText($html));
        $this->assertSame('', ContentText::toText(null));
        $this->assertSame('Просто текст', ContentText::toText('Просто текст'));
    }
}
