<?php

namespace Tests\Unit\Crm;

use App\Support\Crm\ClientNameIndex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Сопоставление имени из управленческой таблицы с клиентом в базе.
 *
 * Все примеры взяты из настоящих данных: слева — как клиента зовут в таблице
 * отдела, справа — как он записан в 1С. Пока эти правила держатся, импорт
 * находит клиента; стоит им сломаться — планы просто не запишутся, и заметить
 * это можно будет лишь по отчёту.
 */
class ClientNameIndexTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function realPairs(): array
    {
        return [
            ['ИП с городом', 'Ракович Владимир Сергеевич ИП, г.Москва', 'Ракович Владимир Сергеевич'],
            ['ООО спереди', 'Гевея, г. Москва', 'ООО Гевея'],
            ['форма и город без запятой', 'Авента ООО г.Новосибирск', 'ООО Авента'],
            ['лишний пробел перед запятой', 'Ковалева Елена Владимировна ИП ,г.Москва', 'Ковалева Елена Владимировна'],
            ['точка внутри названия', 'Яндекс.Лавка ООО, г. Москва', 'ООО Яндекс.Лавка'],
            ['область вместо города', 'Затулина Ирина Андреевна ИП, МО', 'Затулина Ирина Андреевна'],
            ['составной город', 'Афонина Алина Анатольевна ИП, г.Санкт-Петербург', 'Афонина Алина Анатольевна'],
        ];
    }

    #[Test]
    #[TestDox('Клиент находится, хотя таблица и 1С называют его по-разному')]
    #[\PHPUnit\Framework\Attributes\DataProvider('realPairs')]
    public function it_matches_real_world_pairs(string $case, string $inSheet, string $inCrm): void
    {
        $index = new ClientNameIndex;
        $index->add(42, $inCrm);

        $this->assertSame([42], $index->find($inSheet), "Не сопоставилось: {$case}");
    }

    #[Test]
    #[TestDox('Полное имя находит карточку, записанную инициалами')]
    public function it_matches_initials_in_crm(): void
    {
        $index = new ClientNameIndex;
        $index->add(7, 'ИП Железняк Д.И.');

        $this->assertSame([7], $index->find('Железняк Денис Имранович ИП, г. Москва'));
    }

    #[Test]
    #[TestDox('Название не съедается правилом про город, даже если начинается на «г»')]
    public function it_keeps_names_starting_with_g(): void
    {
        // «Гевея» едва не превратилась в «г.евея» — правило требует точку или пробел.
        $this->assertSame('гевея', ClientNameIndex::core('Гевея, г. Москва'));
        $this->assertSame('гущина юлия владимировна', ClientNameIndex::core('Гущина Юлия Владимировна ИП, г.Москва'));
    }

    #[Test]
    #[TestDox('Двое клиентов с одинаковым ядром имени возвращаются оба — угадывать нечего')]
    public function it_returns_all_candidates_when_ambiguous(): void
    {
        $index = new ClientNameIndex;
        $index->add(1, 'Иванов Сергей Васильевич');
        $index->add(2, 'ИП Иванов Сергей Васильевич');

        $this->assertCount(2, $index->find('Иванов Сергей Васильевич г.Омск'));
    }

    #[Test]
    #[TestDox('Разные клиенты не схлопываются в одного')]
    public function it_does_not_confuse_different_clients(): void
    {
        $index = new ClientNameIndex;
        $index->add(1, 'Горбачев Павел Викторович');
        $index->add(2, 'Горбачев Кирилл Александрович');

        $this->assertSame([1], $index->find('Горбачев Павел Викторович ИП, г.Москва'));
        $this->assertSame([2], $index->find('Горбачев Кирилл Александрович ИП, г.Москва'));
    }

    #[Test]
    #[TestDox('Один клиент под двумя именами остаётся одним кандидатом')]
    public function it_keeps_single_client_single(): void
    {
        $index = new ClientNameIndex;
        // erp_name и name у клиента часто различаются лишь формой записи.
        $index->add(5, 'ООО Гевея', 'Гевея');

        $this->assertSame([5], $index->find('Гевея, г. Москва'));
    }

    #[Test]
    #[TestDox('Пустое имя ничего не находит')]
    public function it_ignores_empty_names(): void
    {
        $index = new ClientNameIndex;
        $index->add(1, '', '   ');

        $this->assertSame([], $index->find(''));
        $this->assertSame([], $index->find('ООО'));
    }
}
