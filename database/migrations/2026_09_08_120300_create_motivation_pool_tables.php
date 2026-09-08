<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Раздача партнёров из Пула пакетами (эпик mot-00, карточка mot-20; пп. 8.2–8.4 Положения).
 *
 * Пул на 08.09.2026 — 618 ничейных партнёров, из которых отгружались когда-либо четверо.
 * Это холодная база, а не спящие клиенты, поэтому раздача идёт пакетами с приоритетом тех,
 * у кого есть история покупок, и со сроками: не связался за отведённые дни — партнёр
 * возвращается в Пул. Кран (blocked_by_tap) не выдаёт новый пакет, пока не отработан прежний.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_pool_packages', function (Blueprint $table) {
            $table->comment('Пакеты партнёров, выданные менеджеру из Пула: сроки первого контакта и первой отгрузки');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('personal_manager_id')->comment('Кому выдан пакет (personal_managers.id)')->constrained('personal_managers')->cascadeOnDelete();
            $table->date('issued_on')->comment('Дата выдачи пакета');
            $table->foreignId('issued_by')->nullable()->comment('Кто выдал (users.id)')->constrained('users')->nullOnDelete();
            $table->date('contact_due_on')->comment('Срок фиксации первого контакта по партнёрам пакета (п. 8.3)');
            $table->date('shipment_due_on')->comment('Срок оформления первой отгрузки (п. 8.3)');
            $table->string('status', 20)->default('active')->comment("Статус: 'active' — в работе, 'closed' — отработан, 'blocked_by_tap' — выдача остановлена правилом крана (п. 8.4)");
            $table->string('comment', 500)->nullable()->comment('Пояснение к выдаче');
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->index(['personal_manager_id', 'issued_on'], 'motivation_pool_packages_manager_idx');
        });

        Schema::create('motivation_pool_package_items', function (Blueprint $table) {
            $table->comment('Партнёры внутри выданного пакета: что с каждым произошло и в какой срок');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('package_id')->comment('Пакет (motivation_pool_packages.id)')->constrained('motivation_pool_packages')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('Партнёр (users.id)')->constrained('users')->cascadeOnDelete();
            $table->timestamp('first_contact_at')->nullable()->comment('Когда зафиксирован первый контакт');
            $table->timestamp('first_shipment_at')->nullable()->comment('Когда оформлена первая отгрузка');
            $table->timestamp('returned_to_pool_at')->nullable()->comment('Когда партнёр возвращён в Пул по истечении срока');
            $table->string('outcome', 20)->default('in_progress')->comment("Исход: 'in_progress' — в работе, 'converted' — довели до отгрузки, 'returned' — вернули в Пул");
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->unique(['package_id', 'user_id'], 'motivation_pool_package_items_uniq');
            $table->index('user_id', 'motivation_pool_package_items_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_pool_package_items');
        Schema::dropIfExists('motivation_pool_packages');
    }
};
