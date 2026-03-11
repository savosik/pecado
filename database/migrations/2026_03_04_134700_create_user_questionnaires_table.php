<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // О бизнесе
            $table->json('business_type')->nullable();        // множественный выбор: секс-шоп, интернет-магазин, маркетплейс, шоурум, другое
            $table->string('business_name')->nullable();       // Название компании/магазина
            $table->string('website_url')->nullable();         // Сайт или соц.сети

            // Опыт и объёмы
            $table->string('years_in_business')->nullable();   // менее 1 года, 1-3 года, 3-5 лет, 5+
            $table->string('monthly_order_volume')->nullable();// ожидаемый объём закупок
            $table->boolean('has_physical_store')->default(false);
            $table->string('store_count')->nullable();         // кол-во торговых точек

            // Предпочтения
            $table->json('product_categories')->nullable();    // интересующие категории


            // Финал
            $table->string('how_found_us')->nullable();        // как узнали о нас
            $table->text('additional_info')->nullable();        // доп. информация

            $table->timestamp('completed_at')->nullable();     // дата завершения
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_questionnaires');
    }
};
