<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История свободных заметок о клиенте.
 *
 * Заметки — коллективный документ отдела: их правит и менеджер, и РОП, и (позже) ИИ-агент.
 * «Кто-то переписал, а что было — не знаем» это потеря знания, а хранение предыдущей
 * версии текста дешевле спора о ней. Пишем ДО-версию при каждом фактическом изменении.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_client_profile_revisions', function (Blueprint $table) {
            $table->comment('История правок свободных заметок о клиенте — кто и что переписал');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('crm_client_profile_id')
                ->comment('Профиль клиента (crm_client_profiles.id)')
                ->constrained('crm_client_profiles')
                ->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()
                ->comment('Кто сделал правку (users.id); NULL — сотрудник удалён')
                ->constrained('users')
                ->nullOnDelete();

            $table->longText('notes_md')->nullable()
                ->comment('Содержимое заметок ДО правки; NULL — заметок не было');

            $table->timestamp('created_at')->nullable()->comment('Когда сделана правка');
            $table->timestamp('updated_at')->nullable()->comment('Служебное поле Eloquent, ревизии не редактируются');

            $table->index(['crm_client_profile_id', 'created_at'], 'crm_profile_revisions_profile_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_client_profile_revisions');
    }
};
