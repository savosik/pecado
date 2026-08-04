<?php

namespace Tests;

use App\Support\Crm\CrmSource;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Тесты не зависят от фронтенд-сборки.
     *
     * Любая страница, отрендеренная через Inertia, тянет @vite и требует либо
     * `public/hot` (живой dev-сервер в контейнере), либо `public/build/manifest.json`.
     * Нет ни того, ни другого — десятки тестов падают с `ViewException: Vite manifest
     * not found`, хотя сломан не код, а окружение. Диагностика при этом уводит в сторону:
     * падают тесты, которые правок не касались.
     *
     * `withoutVite()` подменяет директиву заглушкой — тестам разметка ассетов не нужна.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Источник записей CRM — статическое состояние процесса: без сброса
        // «агент», поднятый одним тестом, протёк бы в следующие и пометил бы
        // чужие записи чужим источником.
        CrmSource::reset();
    }

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
