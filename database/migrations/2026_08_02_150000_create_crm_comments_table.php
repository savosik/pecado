<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Комментарии менеджеров: полиморфная лента по клиенту, заказу и реализации.
 *
 * Ключевое здесь — колонка client_user_id рядом с полиморфной связью. Требование
 * «в карточке клиента видны все комментарии, где бы их ни оставили» без неё означало бы
 * собрать все заказы клиента, все его отгрузки и выбрать комментарии по двум спискам ID —
 * три запроса, растущие вместе с историей клиента. С ней лента строится одним
 * индексированным запросом.
 *
 * Записи, не сводящиеся к клиенту (заказ без user_id — партнёрские из 1С), живут
 * при своей сущности с client_user_id = null и просто не попадают ни в чью ленту.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_comments', function (Blueprint $table) {
            $table->comment('Комментарии менеджеров к клиентам, заказам и реализациям (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->string('commentable_type')
                ->comment('Класс сущности, к которой оставлен комментарий (App\Models\User|Order|Shipment)');
            $table->unsignedBigInteger('commentable_id')
                ->comment('ID сущности в её таблице');

            $table->foreignId('client_user_id')
                ->nullable()
                ->comment('Клиент, в ленту которого попадёт комментарий (users.id); NULL — сущность к клиенту не сводится')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->comment('Автор комментария — сотрудник (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->text('body')->comment('Текст комментария, обычный текст без разметки');

            $table->boolean('is_pinned')->default(false)
                ->comment('Закреплён вверху ленты: важное, что не должно утонуть в хронологии');

            $table->timestamp('created_at')->nullable()->comment('Когда оставлен комментарий');
            $table->timestamp('updated_at')->nullable()->comment('Когда последний раз отредактирован');
            $table->softDeletes()->comment('Мягкое удаление: лента — рабочая переписка, восстановимость важнее чистоты');

            $table->index(['commentable_type', 'commentable_id'], 'crm_comments_commentable_idx');
            $table->index(['client_user_id', 'created_at'], 'crm_comments_client_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_comments');
    }
};
