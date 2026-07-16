<?php

namespace App\Services\Bi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Исполнитель read-only SQL для ИИ-агента отчётов.
 *
 * Смысл класса в том, что коннектом владеет ОН, а не агент. Это не формальность:
 * max_execution_time — сессионная переменная, и клиент, держащий коннект, снимает
 * её одной строкой (`SET SESSION max_execution_time=0`), после чего таймаут
 * перестаёт существовать. Проверено на живой базе: запрос с декартовым произведением
 * после такой строки молотил больше двух минут. Поэтому агент не получает ни кредов,
 * ни коннекта — только этот фасад, который выставляет таймаут перед каждым запросом.
 *
 * Рубежи обороны, от надёжного к вспомогательному:
 *
 *  1. Гранты. bi_agent имеет только SELECT (и не имеет прав на таблицы с секретами) —
 *     гарантия движка. Всё остальное здесь — глубина эшелона, а не основа.
 *  2. Таймаут сессии, выставляемый перед каждым запросом (агент его не видит).
 *  3. Лексическая проверка первого слова — отсекает очевидное раньше, чем сервер.
 *  4. Принудительный LIMIT и потолок на число возвращаемых строк.
 *  5. Лог каждого запроса: что именно спросил агент, всегда должно быть видно.
 */
class ReadOnlySqlRunner
{
    /**
     * Логические имена БД для агента → коннект и таймаут.
     *
     * Таймаут у прайса жёстче осознанно: individual_prices партиционирована
     * HASH(partner_id) на 64 части, и корректный запрос обязан пройти по партиции,
     * уложившись в доли секунды. Запрос, которому не хватило 5 секунд, читает всю
     * таблицу (17+ млн строк на проде) и вымывает буферный пул живой витрины —
     * такой запрос неправилен по определению, и убить его правильно.
     */
    private const DATABASES = [
        'main' => ['connection' => 'analytics', 'timeout_ms' => 15000, 'source_connection' => 'mysql'],
        'prices' => ['connection' => 'analytics_prices', 'timeout_ms' => 5000, 'source_connection' => 'prices'],
    ];

    /** Потолок строк в ответе: контекст модели не резиновый. */
    public const MAX_ROWS = 500;

    /**
     * Команды, не меняющие данные. Права всё равно не дадут выполнить остальное —
     * но понятная ошибка лучше, чем ERROR 1142 из недр драйвера.
     */
    private const READ_ONLY_STATEMENTS = ['SELECT', 'WITH', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];

    /**
     * @return list<string>
     */
    public function databases(): array
    {
        return array_keys(self::DATABASES);
    }

    public function connectionFor(string $database): string
    {
        return self::DATABASES[$this->assertKnown($database)]['connection'];
    }

    /**
     * Коннект приложения к той же БД — только для метаданных.
     *
     * Нужен там, где bi_agent слеп по построению: комментарий вьюхи в MySQL всегда
     * равен 'VIEW', а описание базовой таблицы под ней bi_agent прочитать не может,
     * потому что прав на саму таблицу у него нет. Приложение читает описание и решает,
     * что рассказать агенту; сам коннект агенту не достаётся ни в каком виде.
     */
    public function sourceConnectionFor(string $database): string
    {
        return self::DATABASES[$this->assertKnown($database)]['source_connection'];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, row_count: int, truncated: bool, sql: string}
     *
     * @throws \InvalidArgumentException при недопустимом запросе или неизвестной БД
     */
    public function select(string $database, string $sql, int $limit = self::MAX_ROWS): array
    {
        $database = $this->assertKnown($database);
        $sql = trim($sql);
        $limit = max(1, min($limit, self::MAX_ROWS));

        $statement = $this->firstWord($sql);
        if (! in_array($statement, self::READ_ONLY_STATEMENTS, true)) {
            throw new \InvalidArgumentException(
                'Допустимы только читающие запросы ('.implode(', ', self::READ_ONLY_STATEMENTS).'). Получено: '.($statement ?: '—')
            );
        }

        // WITH разрешён только как CTE перед SELECT: WITH ... DELETE тоже начинается с WITH.
        if ($statement === 'WITH' && ! preg_match('/\bselect\b/i', $sql)) {
            throw new \InvalidArgumentException('WITH допустим только вместе с SELECT.');
        }

        $sql = $this->withLimit($sql, $statement, $limit);
        $config = self::DATABASES[$database];
        $connection = DB::connection($config['connection']);

        // Таймаут ставим здесь, а не в конфиге коннекта: PDO применил бы его один раз
        // при установке соединения, а persistent-коннект переживает много запросов.
        $connection->statement('SET SESSION max_execution_time = '.(int) $config['timeout_ms']);

        $started = microtime(true);
        try {
            $rows = $connection->select($sql);
        } finally {
            Log::channel('bi')->info('Запрос ИИ-агента к БД', [
                'actor' => $this->actor(),
                'database' => $database,
                'sql' => $sql,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        }

        $rows = array_map(fn ($row) => (array) $row, $rows);
        $truncated = count($rows) > $limit;

        return [
            'rows' => array_slice($rows, 0, $limit),
            'row_count' => count($rows),
            'truncated' => $truncated,
            'sql' => $sql,
        ];
    }

    /**
     * Кто выполняет запрос — имя из токена менеджера (кладёт middleware). Для
     * локального stdio-режима HTTP-запроса нет, поэтому помечаем как local-cli.
     */
    private function actor(): string
    {
        $request = request();
        $actor = $request->attributes->get(\App\Http\Middleware\AuthenticateAnalyticsMcp::ACTOR_ATTRIBUTE);

        return is_string($actor) && $actor !== '' ? $actor : 'local-cli';
    }

    private function assertKnown(string $database): string
    {
        if (! isset(self::DATABASES[$database])) {
            throw new \InvalidArgumentException(
                "Неизвестная база «{$database}». Доступны: ".implode(', ', array_keys(self::DATABASES))
            );
        }

        return $database;
    }

    private function firstWord(string $sql): string
    {
        // Ведущие комментарии — способ спрятать настоящее начало запроса.
        $normalized = preg_replace('~^(\s|/\*.*?\*/|--[^\n]*\n|#[^\n]*\n)+~s', '', $sql) ?? $sql;

        return strtoupper((string) strtok(ltrim($normalized), " \t\n\r("));
    }

    /**
     * LIMIT дописываем только выборкам: у SHOW/DESCRIBE/EXPLAIN своя форма,
     * а результат и так короткий. Проверка нарочно грубая — если LIMIT уже есть
     * где угодно, не трогаем, а потолок строк подстрахует на выдаче.
     */
    private function withLimit(string $sql, string $statement, int $limit): string
    {
        if (! in_array($statement, ['SELECT', 'WITH'], true)) {
            return $sql;
        }
        if (preg_match('/\blimit\s+\d+/i', $sql)) {
            return $sql;
        }

        return rtrim($sql, "; \t\n\r")." LIMIT {$limit}";
    }
}
