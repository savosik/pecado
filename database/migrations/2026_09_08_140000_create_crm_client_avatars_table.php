<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аватарки партнёров в CRM: загруженные менеджером и нарисованные ИИ.
 *
 * Отдельная таблица, а не колонки в `crm_client_profiles`: у аватарки своя
 * жизнь — попытки генерации, промт, модель, кто загрузил. В профиле, который
 * читается на каждом экране CRM, эти поля были бы мёртвым грузом.
 *
 * Файлы лежат на приватном диске `crm-avatars` и отдаются только маршрутом CRM.
 * В медиатеке (публичный диск) их нет намеренно — партнёр не должен увидеть
 * в кабинете, каким его нарисовал отдел продаж.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_client_avatars', function (Blueprint $table) {
            $table->comment('Аватарки партнёров в CRM: файл, происхождение и попытки ИИ-генерации');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('user_id')
                ->unique()
                ->comment('Партнёр (users.id); одна аватарка на партнёра')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('path')->nullable()
                ->comment('Путь файла на диске crm_avatars.disk; NULL — файла ещё нет (только попытки)');

            $table->string('disk', 40)->nullable()
                ->comment('Диск, на котором лежит файл — на случай переезда хранилища');

            $table->string('source', 20)->nullable()
                ->comment("Откуда взялась: 'manual' — загрузил менеджер, 'ai' — нарисовала модель");

            $table->unsignedInteger('size')->nullable()
                ->comment('Размер файла в байтах');

            $table->string('mime', 40)->nullable()
                ->comment("MIME готового файла — всегда 'image/webp' после пережатия");

            $table->string('checksum', 64)->nullable()
                ->comment('SHA-256 файла: он же ETag для условных запросов браузера');

            $table->text('prompt')->nullable()
                ->comment('Промт, по которому рисовали — чтобы понять, почему вышло именно так');

            $table->string('image_model', 100)->nullable()
                ->comment('Модель-художник (OpenRouter)');

            $table->string('text_model', 100)->nullable()
                ->comment('Текстовая модель, придумавшая промт (OpenRouter)');

            $table->foreignId('uploaded_by')->nullable()
                ->comment('Кто загрузил вручную (users.id); NULL — рисовала система')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('generated_at')->nullable()
                ->comment('Когда появился нынешний файл');

            $table->unsignedSmallInteger('attempts')->default(0)
                ->comment('Неудачных попыток генерации подряд; сбрасывается при успехе');

            $table->timestamp('failed_at')->nullable()
                ->comment('Когда сорвалась последняя попытка — от неё считается пауза');

            $table->string('failure_reason')->nullable()
                ->comment('Почему сорвалась: текст ошибки для менеджера и логов');

            $table->timestamps();

            // Ночная пачка ищет тех, у кого файла нет и пауза после неудачи вышла.
            $table->index(['path', 'failed_at'], 'crm_client_avatars_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_client_avatars');
    }
};
