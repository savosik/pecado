<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ссылки-доступы для кладовщиков (pick-17): вход в кабинет склада по ссылке без логина и пароля.
 * Начальник склада выпускает ссылку, пересылает в мессенджер; перевыпуск отключает старые телефоны.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_access_links', function (Blueprint $table) {
            $table->comment('Ссылки входа в кабинет склада без пароля: одна ссылка — одна учётная запись кладовщика');
            $table->id()->comment('Первичный ключ');
            $table->string('name', 120)->comment('Название для начальника склада: «Стойка выдачи», «Смена Б»');
            $table->foreignId('user_id')->comment('Учётная запись кладовщика, в которую входит ссылка (users.id)')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique()->comment('sha256 от секрета ссылки; сам секрет хранится зашифрованным в token_encrypted');
            $table->text('token_encrypted')->comment('Секрет ссылки, зашифрованный ключом приложения — чтобы показать ссылку повторно');
            $table->unsignedInteger('session_version')->default(1)->comment('Версия доступа: перевыпуск увеличивает её, и сессии старых телефонов закрываются');
            $table->unsignedInteger('uses_count')->default(0)->comment('Сколько раз по ссылке входили');
            $table->timestamp('last_used_at')->nullable()->comment('Последний вход по ссылке');
            $table->timestamp('rotated_at')->nullable()->comment('Когда ссылку перевыпускали в последний раз');
            $table->timestamp('revoked_at')->nullable()->comment('Когда ссылку отключили; отключённая не входит и закрывает сессии');
            $table->foreignId('created_by')->nullable()->comment('Кто выпустил (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения записи');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_access_links');
    }
};
