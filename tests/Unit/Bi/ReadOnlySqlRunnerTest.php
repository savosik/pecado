<?php

namespace Tests\Unit\Bi;

use App\Services\Bi\ReadOnlySqlRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Лексические рубежи ReadOnlySqlRunner.
 *
 * Главная гарантия — гранты bi_agent на уровне СУБД, и здесь она не проверяется:
 * тесты идут на SQLite (phpunit.xml), где нет ни пользователей MySQL, ни прав.
 * Проверяется второй рубеж: что заведомо опасный запрос отсекается ДО обращения
 * к базе. Именно поэтому все проверки валидации в select() стоят до DB::connection() —
 * иначе этот тест не смог бы существовать без MySQL.
 */
class ReadOnlySqlRunnerTest extends TestCase
{
    private ReadOnlySqlRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ReadOnlySqlRunner;
    }

    /**
     * @return list<array{string}>
     */
    public static function writeStatements(): array
    {
        return [
            ['DROP TABLE orders'],
            ['DELETE FROM orders'],
            ['UPDATE users SET email = 1'],
            ['INSERT INTO orders (id) VALUES (1)'],
            ['TRUNCATE orders'],
            ['ALTER TABLE orders ADD COLUMN x INT'],
            ['CREATE TABLE zzz (id INT)'],
            ['GRANT ALL ON *.* TO bi_agent'],
            // Снятие таймаута — тот самый обход, ради которого коннектом владеет
            // раннер, а не агент: с сырыми кредами эта строка отключает защиту.
            ['SET SESSION max_execution_time = 0'],
        ];
    }

    #[Test]
    #[DataProvider('writeStatements')]
    public function it_rejects_statements_that_are_not_reads(string $sql): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Допустимы только читающие запросы');

        $this->runner->select('main', $sql);
    }

    /**
     * @return list<array{string}>
     */
    public static function maskedStatements(): array
    {
        return [
            ['/* безобидный комментарий */ DELETE FROM orders'],
            ["-- просто SELECT, честное слово\nDROP TABLE orders"],
            ["# ещё комментарий\nUPDATE users SET email = 1"],
            ['   /* a */  /* b */   TRUNCATE orders'],
        ];
    }

    /**
     * Ведущий комментарий — самый дешёвый способ спрятать настоящее начало запроса
     * от наивной проверки первого слова.
     */
    #[Test]
    #[DataProvider('maskedStatements')]
    public function it_sees_through_leading_comments(string $sql): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Допустимы только читающие запросы');

        $this->runner->select('main', $sql);
    }

    /** WITH начинает и читающий CTE, и WITH ... DELETE. */
    #[Test]
    public function it_rejects_cte_without_select(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WITH допустим только вместе с SELECT');

        $this->runner->select('main', 'WITH x AS (VALUES ROW(1)) DELETE FROM orders');
    }

    #[Test]
    public function it_rejects_unknown_database(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Неизвестная база');

        $this->runner->select('production', 'SELECT 1');
    }

    #[Test]
    public function it_exposes_only_the_two_intended_databases(): void
    {
        // Список идёт в описание инструмента MCP: лишняя база здесь — лишняя
        // база в руках агента.
        $this->assertSame(['main', 'prices'], $this->runner->databases());
    }

    #[Test]
    public function it_maps_databases_to_read_only_connections(): void
    {
        // Коннекты analytics* ходят под bi_agent. Если сюда однажды попадёт
        // 'mysql' или 'prices', агент получит боевые креды приложения.
        $this->assertSame('analytics', $this->runner->connectionFor('main'));
        $this->assertSame('analytics_prices', $this->runner->connectionFor('prices'));
    }
}
