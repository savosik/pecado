<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ревизия последнего применённого сообщения 1С (протокол v15.16.0).
 *
 * Порядок доставки не гарантирован ни между очередями (у каждой свой connection
 * и свой набор воркеров), ни внутри очереди при numprocs > 1. Дедупликация по
 * message_id от этого не защищает: два разных сообщения по одному документу
 * имеют разные message_id и оба будут применены в порядке доставки.
 *
 * Отличить свежее от устаревшего по erp_updated_at тоже нельзя — у двух отправок
 * по одному документу метка совпадает до секунды (живой случай: сообщения
 * 4609584 и 4609585 по заказу 29УТ-012045, оба 15:41:28, содержательно разные).
 *
 * Колонка nullable: документы, заведённые до v15.16.0, и каналы, где 1С ещё не
 * включила revision, проверку не проходят вовсе. Отсутствие ревизии означает
 * «применять как раньше», а не «ревизия 0».
 *
 * Расходных ордеров в списке нет намеренно: у документа в 1С отключена история
 * данных, и номера ревизии там не существует.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const TABLES = [
        'orders' => 'заказа',
        'shipments' => 'реализации',
        'payments' => 'платежа',
        'returns' => 'возврата',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $subject) {
            Schema::table($table, function (Blueprint $blueprint) use ($subject) {
                $blueprint->unsignedBigInteger('applied_revision')->nullable()
                    ->comment("Ревизия последнего применённого сообщения 1С по этому документу ({$subject}). Сообщение с revision меньше или равным этому значению отбрасывается как устаревшее. NULL — 1С ещё не присылала revision по документу");
            });
        }

        // В журнале шины появился четвёртый статус. Через change(), а не ALTER:
        // на MySQL колонка — ENUM, на SQLite (тесты) — varchar с CHECK-констрейнтом.
        // Расширять нужно оба, иначе запись со статусом `stale` не пройдёт.
        Schema::table('erp_bus_messages', function (Blueprint $blueprint) {
            $blueprint->enum('status', ['success', 'recovered', 'stale', 'failed'])
                ->default('success')
                ->comment("Статус обработки сообщения: 'success' — успех, 'recovered' — обработано с восстановлением сущности (1С потеряла событие создания), 'stale' — отброшено как устаревшее (ревизия документа не новее уже применённой), 'failed' — ошибка")
                ->change();
        });
    }

    public function down(): void
    {
        // Отброшенные сообщения при откате приравниваем к успешным: сужённый
        // набор значений их иначе не пропустит.
        DB::table('erp_bus_messages')->where('status', 'stale')->update(['status' => 'success']);

        Schema::table('erp_bus_messages', function (Blueprint $blueprint) {
            $blueprint->enum('status', ['success', 'recovered', 'failed'])
                ->default('success')
                ->comment("Статус обработки сообщения: 'success' — успех, 'recovered' — обработано с восстановлением сущности (1С потеряла событие создания), 'failed' — ошибка")
                ->change();
        });

        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('applied_revision');
            });
        }
    }
};
