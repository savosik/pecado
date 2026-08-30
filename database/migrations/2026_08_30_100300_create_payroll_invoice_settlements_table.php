<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Мост «накладная → срок оплаты → дата закрытия → задержка» (эпик pay-00, карточка pay-01/03).
 *
 * Даты фактической оплаты в модели нет: shipments.paid_at снесён 25.08.2026, регистр
 * несёт только settled_amount. Дата восстанавливается из фактических строк
 * settlement_entries type=payment_in, чьё settlement_object_name содержит номер
 * реализации. Ручная разметка РОПа живёт в той же строке (manual_*) и ребилдом
 * не затирается — как сейчас он размечает просрочку в Excel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_invoice_settlements', function (Blueprint $table) {
            $table->comment('Накладная → срок оплаты → дата закрывающего платежа → задержка в днях; основа штрафа за финансовую дисциплину');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('shipment_id')->comment('Реализация (shipments.id)')->constrained('shipments')->cascadeOnDelete();
            $table->uuid('shipment_uuid')->comment('UUID реализации — дубль для сверки с settlement_entries.document_uuid без join');
            $table->string('erp_number', 30)->nullable()->comment('Номер реализации в 1С как в shipments.erp_number («29УТ-007699»)');
            $table->string('number_key', 30)->nullable()->comment('Нормализованный номер для поиска платежей: префикс + число без ведущих нулей, латиница приведена к кириллице («29УТ-7699»)');
            $table->foreignId('user_id')->nullable()->comment('Партнёр (users.id)')->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->comment('Контрагент (companies.id)')->constrained('companies')->nullOnDelete();
            $table->foreignId('personal_manager_id')->nullable()->comment('Менеджер партнёра на момент проекции (personal_managers.id)')->constrained('personal_managers')->nullOnDelete();
            $table->date('shipped_on')->nullable()->comment('Дата документа реализации (erp_created_at)');
            $table->decimal('total_amount', 14, 2)->default(0)->comment('Сумма накладной, ₽');
            $table->date('due_on')->nullable()->comment('Срок оплаты: последняя дата плановых строк регистра по документу; NULL — графика нет');
            $table->string('due_source', 20)->nullable()->comment("Откуда срок: 'schedule' — график регистра, 'shipment_column' — shipments.payment_due_date");
            $table->decimal('matched_paid_amount', 14, 2)->default(0)->comment('Сумма платежей, сопоставленных по номеру, ₽');
            $table->date('matched_settled_on')->nullable()->comment('Дата закрывающего платежа по сопоставлению: первый день, когда накопленные платежи покрыли сумму');
            $table->json('payments')->nullable()->comment('Улики: сопоставленные платежи [{entry_uuid, date, amount, document_number}]');
            $table->string('payment_status', 20)->nullable()->comment("Копия shipments.payment_status на момент проекции: 'unpaid', 'partial', 'paid', 'overpaid'");
            $table->date('manual_settled_on')->nullable()->comment('Дата закрытия, проставленная РОПом вручную — приоритетнее сопоставления');
            $table->string('manual_comment')->nullable()->comment('Основание ручной даты');
            $table->foreignId('manual_by_user_id')->nullable()->comment('Кто проставил вручную (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('manual_set_at')->nullable()->comment('Когда проставлено вручную');
            $table->date('settled_on')->nullable()->comment('Действующая дата закрытия = ручная, иначе сопоставленная');
            $table->string('settled_source', 10)->nullable()->comment("Источник действующей даты: 'manual' или 'matched'; NULL — дата не восстановлена");
            $table->unsignedSmallInteger('delay_calendar_days')->nullable()->comment('Задержка от срока оплаты до закрытия, календарных дней (0 — в срок); NULL — дата не восстановлена');
            $table->unsignedSmallInteger('delay_working_days')->nullable()->comment('Задержка в рабочих днях по производственному календарю; по ней выбирается ступень штрафа');
            $table->boolean('needs_review')->default(false)->comment('Оплачена по данным 1С, но дата закрытия не восстановлена — очередь на ручную разметку');
            $table->timestamp('computed_at')->nullable()->comment('Когда пересчитано проектором');
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->unique('shipment_id', 'payroll_invoices_shipment_uniq');
            $table->index('number_key', 'payroll_invoices_number_key_idx');
            $table->index(['personal_manager_id', 'settled_on'], 'payroll_invoices_manager_settled_idx');
            $table->index(['user_id', 'due_on'], 'payroll_invoices_user_due_idx');
            $table->index('needs_review', 'payroll_invoices_needs_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_invoice_settlements');
    }
};
