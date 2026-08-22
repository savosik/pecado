<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Письмо знает не только партнёра, но и человека.
 *
 * Раньше письмо бухгалтеру подшивалось к партнёру — и всё. Открыть карточку
 * человека и увидеть, что ему писали, было нельзя: связи не существовало.
 *
 * Колонки nullable намеренно: адрес узнаётся не всегда, и письмо на незнакомый
 * ящик должно уходить как прежде, а не падать.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('client_user_id')
                ->comment('Человек из справочника, которому адресовано письмо (contacts.id); NULL — адрес не узнан')
                ->constrained('contacts')
                ->nullOnDelete();
        });

        Schema::table('sent_emails', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('client_user_id')
                ->comment('Человек из справочника, которому ушло письмо (contacts.id); NULL — адрес не узнан')
                ->constrained('contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });

        Schema::table('sent_emails', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });
    }
};
