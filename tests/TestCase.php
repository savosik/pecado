<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Очищаем таблицу individual_prices в prices DB после каждого теста,
     * чтобы данные не «протекали» между тестами (prices DB не покрыта
     * транзакциями RefreshDatabase, которая управляет только SQLite).
     */
    protected function tearDown(): void
    {
        // Очищаем prices DB ДО parent::tearDown(), который уничтожает app-контейнер
        try {
            DB::connection('prices')->table('individual_prices')->delete();
        } catch (\Throwable) {
            // prices DB может быть недоступна или таблица не существует — не критично
        }

        parent::tearDown();
    }
}
