<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс по `processed_at` в журнале дедупликации входящих ERP-сообщений.
 *
 * Таблица создавалась только с первичным ключом по `message_id`, поэтому любой
 * запрос по времени шёл полным сканом: и сортировка в админке «Шина ERP», и
 * ретенция `erp:cleanup-processed`, которая появляется этим же релизом и иначе
 * перебирала бы всю таблицу каждую ночь.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_processed_messages', function (Blueprint $table) {
            $table->index('processed_at', 'erp_processed_messages_processed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('erp_processed_messages', function (Blueprint $table) {
            $table->dropIndex('erp_processed_messages_processed_at_index');
        });
    }
};
