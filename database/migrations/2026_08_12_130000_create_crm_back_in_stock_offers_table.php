<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал предложений «товар снова в продаже» (crm-31).
 *
 * Существует ради дедупликации. Остаток около порога дребезжит, и без журнала
 * один клиент получил бы серию одинаковых писем про один и тот же товар —
 * повод, который должен был обрадовать, превратился бы в спам.
 *
 * Отдельная таблица, а не флаг на письме: черновик менеджер может удалить,
 * а факт «этот товар мы этому клиенту уже предлагали» должен пережить удаление.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_back_in_stock_offers', function (Blueprint $table) {
            $table->comment('Кому и какой вернувшийся товар уже предлагали — защита от повторных писем (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('client_user_id')
                ->comment('Клиент, которому предложили (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->comment('Товар, вернувшийся в продажу (products.id)')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('email_id')
                ->nullable()
                ->comment('Черновик письма, в который вошёл товар (crm_emails.id); NULL — письмо удалено')
                ->constrained('crm_emails')
                ->nullOnDelete();

            $table->timestamp('offered_at')->comment('Когда предложение сформировано');

            $table->timestamp('created_at')->nullable()->comment('Когда запись создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись менялась');

            // Основной запрос — «предлагали ли мы это за последнее окно».
            $table->index(['client_user_id', 'product_id', 'offered_at'], 'crm_back_in_stock_offers_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_back_in_stock_offers');
    }
};
