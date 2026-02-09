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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('country', ['RU', 'BY', 'KZ']);
            $table->string('name'); // Краткое название
            $table->string('legal_name'); // Юридическое наименование
            $table->string('tax_id'); // ИНН (RU) / УНП (BY) / БИН (KZ)
            $table->string('registration_number')->nullable(); // ОГРН (только RU)
            $table->string('tax_code')->nullable(); // КПП (только RU)
            $table->string('okpo_code')->nullable(); // ОКПО (RU/BY)
            $table->text('legal_address'); // Юридический адрес
            $table->text('actual_address')->nullable(); // Фактический адрес
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('erp_id')->nullable()->unique(); // ID в ERP системе
            $table->decimal('latitude', 10, 7)->nullable(); // Широта
            $table->decimal('longitude', 10, 7)->nullable(); // Долгота
            $table->boolean('is_our_company')->default(false); // Наша компания
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
