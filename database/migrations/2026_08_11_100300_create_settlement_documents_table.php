<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Документы-регистраторы регистра взаиморасчётов (карточка fin-04, протокол v16.0.0).
 *
 * Одна строка на документ 1С, породивший движения. Таблица служебная и содержит
 * ровно то, чего нет больше нигде, — состояние документа как целого.
 *
 * Зачем понадобилась. `ErpRevisionGuard` отбрасывает сообщения, обогнавшие свежие,
 * сравнивая ревизию с уже применённой. Для заказов и реализаций отметка лежит
 * на самом документе, а у движений документа на сайте может не быть вовсе
 * (отчёт комиссионера) либо он приедет позже. Хранить отметку в самих движениях
 * нельзя: `settlement.reverted` их удаляет, и следующее устаревшее `settlement.posted`
 * воскресило бы отменённый документ, потому что сравнивать стало бы не с чем.
 *
 * Отсюда и `is_reverted`: отмена проведения переживает удаление движений.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_documents', function (Blueprint $table) {
            $table->comment('Документы-регистраторы регистра взаиморасчётов: отметка применённой ревизии и признак отмены проведения');

            $table->id()->comment('Первичный ключ');
            $table->uuid('uuid')->unique()->comment('UUID документа-регистратора в 1С (document_uuid из сообщений settlement.*)');
            $table->unsignedInteger('applied_revision')->nullable()
                ->comment('Наибольшая применённая ревизия движений документа (settlement.posted / .reverted). Сообщение с меньшей или равной отбрасывается как устаревшее');
            // Отдельный счётчик, а не общий с движениями: график и движения —
            // два независимых потока сообщений об одном документе, и ревизия
            // у них общая. С одной колонкой график с той же ревизией, что уже
            // применённое проведение, отбрасывался бы как устаревший.
            $table->unsignedInteger('applied_schedule_revision')->nullable()
                ->comment('Наибольшая применённая ревизия графика оплаты (payment_schedule.updated)');
            $table->string('document_kind', 40)->nullable()
                ->comment("Вид документа: 'shipment', 'order', 'payment', 'goods_return', 'netting', 'debt_adjustment', 'sale_adjustment', 'commission_report', 'other'. Перечень открытый");
            $table->string('document_number')->nullable()->comment('Номер документа в 1С');
            $table->date('document_date')->nullable()->comment('Дата документа в 1С');
            $table->boolean('is_reverted')->default(false)
                ->comment('true — проведение отменено (settlement.reverted): движений нет, и устаревшее сообщение не должно их воскресить');
            $table->timestamp('last_posted_at')->nullable()->comment('Момент последнего успешного применения движений документа');

            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата изменения записи');

            $table->index(['document_kind', 'document_date'], 'sd_kind_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_documents');
    }
};
