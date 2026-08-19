<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Наша организация (юрлицо), фактически торгующая со склада — например,
     * ООО Пекадо — Москва основной, ИП Елисеев — Москва персональный.
     * Справочная привязка на сайте; в исходящий протокол 1С передаётся
     * опционально и только под флагом ERP_ORGANIZATION_UUID_ENABLED
     * (по умолчанию выключен, до согласования с 1С).
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('is_promo_sample')
                ->constrained('organizations')
                ->nullOnDelete()
                ->comment('Наша организация, торгующая с этого склада (organizations.id). NULL — не задана, организацию определяет 1С по своим правилам');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
