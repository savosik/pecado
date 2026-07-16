<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Комментарии для БД цен — по правилу .claude/rules/db-comments.md.
 *
 * Сплошное покрытие 2026_07_07_120000_add_comments_to_database_schema.php прошло
 * только по основной БД: pecado — 95/95 таблиц и 844/850 столбцов, а individual_prices
 * осталась голой. `db:comments:audit` этого не показывал, потому что ходил лишь по
 * дефолтному коннекту (исправлено там же, опцией --connection).
 *
 * Комментарии здесь — не документация ради документации: их читает ИИ-агент отчётов
 * через information_schema, и именно из них он узнаёт, что JOIN с основной БД
 * невозможен, а запрос без WHERE partner_id прочитает все 17+ млн строк.
 *
 * ПРО АЛГОРИТМЫ (замеры на проде 2026-07-16: 17.4 млн строк, 5.5 GB, живая витрина):
 * алгоритм указан явно, чтобы MySQL отказался выполнять операцию, если она вдруг
 * потребует перестройки таблицы, вместо того чтобы молча переписывать 5.5 GB.
 *   • столбцы — ALGORITHM=INSTANT (проверено: только метаданные);
 *   • таблица — ALGORITHM=INPLACE, LOCK=NONE (INSTANT на комментарий таблицы даёт
 *     ERROR 1845; INPLACE метаданные тоже не перестраивает и не блокирует запись).
 */
return new class extends Migration
{
    protected $connection = 'prices';

    /**
     * Точные определения столбцов взяты из SHOW CREATE TABLE: MODIFY COLUMN требует
     * полного определения, и любое расхождение молча изменило бы тип столбца.
     */
    private const COLUMNS = [
        'partner_id' => [
            'bigint unsigned NOT NULL',
            'Партнёр (users.id в основной БД pecado; FK через границу БД не действует)',
        ],
        'product_id' => [
            'bigint unsigned NOT NULL',
            'Товар (products.id в основной БД pecado; FK через границу БД не действует)',
        ],
        'warehouse_id' => [
            'bigint unsigned NOT NULL',
            'Склад (warehouses.id в основной БД pecado; FK через границу БД не действует)',
        ],
        'price' => [
            'decimal(15,2) NOT NULL',
            'Индивидуальная цена партнёра за единицу товара',
        ],
        'updated_at' => [
            'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'Дата и время последнего обновления цены из 1С; истории изменений нет — перелив затирает значение',
        ],
    ];

    private const TABLE_COMMENT = 'Индивидуальные цены партнёров из 1С. '
        .'Живёт в отдельной БД pecado_prices — JOIN с таблицами основной БД невозможен, '
        .'связывать данные нужно в два запроса через partner_id/product_id. '
        .'Партиционирована HASH(partner_id) на 64 части: запрос с WHERE partner_id читает одну '
        .'партицию, без этого условия — все 17+ млн строк и вымывает буферный пул живой витрины.';

    public function up(): void
    {
        $this->applyColumnComments();

        DB::connection($this->connection)->statement(
            'ALTER TABLE individual_prices COMMENT = '.$this->quote(self::TABLE_COMMENT)
            .', ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    public function down(): void
    {
        $this->applyColumnComments(revert: true);

        DB::connection($this->connection)->statement(
            "ALTER TABLE individual_prices COMMENT = '', ALGORITHM=INPLACE, LOCK=NONE"
        );
    }

    private function applyColumnComments(bool $revert = false): void
    {
        foreach (self::COLUMNS as $column => [$definition, $comment]) {
            DB::connection($this->connection)->statement(
                "ALTER TABLE individual_prices MODIFY COLUMN `{$column}` {$definition} "
                .'COMMENT '.$this->quote($revert ? '' : $comment).', ALGORITHM=INSTANT'
            );
        }
    }

    /**
     * COMMENT в DDL не принимает плейсхолдеры (MySQL: ERROR 1064), поэтому строку
     * приходится вставлять в запрос — но экранированием занимается драйвер, а не мы.
     */
    private function quote(string $value): string
    {
        return DB::connection($this->connection)->getPdo()->quote($value);
    }
};
