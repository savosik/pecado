<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление таблиц Laravel Pulse.
 *
 * Pulse в проекте не использовался, но писал все 10 рекордеров с sample_rate=1
 * в основную БД и чистился только лотереей 1/1000 внутри `pulse:check`. Замер
 * боевой базы 2026-08-09: pulse_aggregates 4536 MB + pulse_entries 1323 MB —
 * 5,8 GB из 6,6 GB всей базы. Из-за этого pre-deploy mysqldump в прод-деплое
 * занимал 7,5 минуты при ~240 MB полезных данных.
 *
 * Класс намеренно НЕ наследует `Laravel\Pulse\Support\PulseMigration`, как это
 * делала миграция создания таблиц: пакет удалён из composer.json тем же
 * коммитом, и наследование уронило бы `migrate:fresh` вместе со всеми тестами
 * на RefreshDatabase.
 *
 * Место на диске возвращает именно DROP TABLE — DELETE оставил бы ibd-файлы
 * прежнего размера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pulse_aggregates');
        Schema::dropIfExists('pulse_entries');
        Schema::dropIfExists('pulse_values');
    }

    public function down(): void
    {
        // Отката нет осознанно: воссоздавать таблицы нечем — пакет удалён из
        // проекта, а его данные ценности не представляли. Откат релиза целиком
        // делается через `git revert`, схема без pulse_* полностью работоспособна.
    }
};
