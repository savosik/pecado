<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Звонок знает, с кем говорили, — карточкой, а не строкой.
 *
 * Свободное поле `contact_name` остаётся: звонить можно и тому, кого в справочнике
 * нет, и заставлять менеджера заводить карточку ради одного разговора незачем.
 * Но если человек в справочнике есть, звонок должен оказаться в его карточке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_calls', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('client_user_id')
                ->comment('Человек из справочника, с которым говорили (contacts.id); NULL — записан только текстом')
                ->constrained('contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_calls', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });
    }
};
