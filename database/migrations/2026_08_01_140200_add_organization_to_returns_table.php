<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Организация возврата (эпик org-00, карточка org-08).
 *
 * Поле **справочное**: возврат создаётся на основании реализаций, а у реализации
 * организация уже известна (org-04) — значит сайт может вывести её сам, не спрашивая 1С.
 * В `return.created.to_erp` организация НЕ отправляется: 1С выводит её из тех же
 * оснований, дублирование создало бы второй источник истины.
 *
 * NULL, если основания принадлежат разным организациям: возврат при этом не дробим —
 * раскладывать документ по основаниям это работа 1С.
 *
 * Таблица называется `returns` (модель — ProductReturn, `$table = 'returns'`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('user_id')
                ->comment('Организация реализаций-оснований (organizations.id). Справочно: 1С определяет её сама. NULL — основания разных организаций либо организация неизвестна')
                ->constrained('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
