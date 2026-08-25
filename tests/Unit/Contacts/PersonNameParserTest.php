<?php

namespace Tests\Unit\Contacts;

use App\Services\Contacts\PersonNameParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Человек или юрлицо.
 *
 * Критерий заказчика: «допустимо Петров И.И. + емейл, недопустимо
 * ООО Ручеек + емейл». Примеры взяты с прода как есть, вместе с городами,
 * пометками «(закрыт)» и двойными пробелами.
 */
class PersonNameParserTest extends TestCase
{
    private PersonNameParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PersonNameParser;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function люди(): array
    {
        return [
            'ИП с городом' => ['Ковалев Александр Юрьевич ИП, г.Москва', 'Ковалев Александр Юрьевич'],
            'ИП с составным городом' => ['Пирогов Сергей Сергеевич ИП, г.Ростов-на-Дону', 'Пирогов Сергей Сергеевич'],
            'без формы вовсе' => ['Гуцол Юлия Александровна', 'Гуцол Юлия Александровна'],
            'с пометкой закрыт' => ['Шичкова Элина Викторовна ИП, г. Москва ( закрыт)', 'Шичкова Элина Викторовна'],
            'ИП без города' => ['Савушкин Сергей Юрьевич ИП', 'Савушкин Сергей Юрьевич'],
            'деревня вместо города' => ['Березкина Ирина Александровна ИП, д.Брилино', 'Березкина Ирина Александровна'],
            'инициалы с точками' => ['Петров И.И.', 'Петров И.И.'],
            'инициалы через пробел' => ['Петров И. И.', 'Петров И. И.'],
            'двойная фамилия' => ['Мамедов-Заде Рустам Ильхамович', 'Мамедов-Заде Рустам Ильхамович'],
            'отчество на оглы' => ['Алиев Рашид Мамед оглы', 'Алиев Рашид Мамед оглы'],
        ];
    }

    #[Test]
    #[DataProvider('люди')]
    public function человека_узнаёт(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->parser->parse($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function не_люди(): array
    {
        return [
            'АО капсом' => ['ТАНДЕР АО'],
            'ООО капсом' => ['ФЕНИКС ООО'],
            'ООО в начале' => ['ООО Ручеек'],
            'РЕДИНГТОН' => ['РЕДИНГТОН ООО, г.Москва'],
            'латиница' => ['Adult Toys Group'],
            'два слова без отчества' => ['Иванов Пётр'],
            'одно слово' => ['Гевея'],
            'пусто' => [''],
            'название с цифрой' => ['Сеть 585 Золотой'],
            'имя из адреса' => ['Zakaz'],
        ];
    }

    #[Test]
    #[DataProvider('не_люди')]
    public function юрлицо_отвергает(string $raw): void
    {
        $this->assertNull($this->parser->parse($raw));
        $this->assertFalse($this->parser->isPerson($raw));
    }

    #[Test]
    public function null_не_роняет(): void
    {
        $this->assertNull($this->parser->parse(null));
    }
}
