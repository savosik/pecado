<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Уборка после переезда контактов в справочник.
 *
 * Люди уже перенесены миграцией 2026_08_23_110000; эти колонки дублируют их
 * свободным текстом. Два представления одного факта — это вечный вопрос
 * «кто прав», и он всегда решается неправильно в самый неудобный момент.
 *
 * `preferred_channel` **остаётся**: это канал общения с компанией как таковой
 * («в эту контору звоним, а не пишем»), а у контакта своя одноимённая колонка
 * про конкретного человека. Разные факты, совпало только имя.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'accountant_name',
        'accountant_contact',
        'owner_name',
        'owner_contact',
        'decision_maker_name',
        'decision_maker_role',
        'decision_maker_contact',
        'decision_maker_birthday',
    ];

    public function up(): void
    {
        Schema::table('crm_client_profiles', function (Blueprint $table) {
            $existing = array_values(array_filter(
                self::COLUMNS,
                fn (string $column): bool => Schema::hasColumn('crm_client_profiles', $column),
            ));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_client_profiles', function (Blueprint $table) {
            $table->string('accountant_name', 255)->nullable()
                ->comment('Бухгалтер: ФИО (историческое поле, данные живут в справочнике контактов)');
            $table->string('accountant_contact', 255)->nullable()
                ->comment('Бухгалтер: телефон и почта (историческое поле)');
            $table->string('owner_name', 255)->nullable()
                ->comment('Собственник: ФИО (историческое поле)');
            $table->string('owner_contact', 255)->nullable()
                ->comment('Собственник: телефон и почта (историческое поле)');
            $table->string('decision_maker_name', 255)->nullable()
                ->comment('ЛПР: ФИО (историческое поле)');
            $table->string('decision_maker_role', 255)->nullable()
                ->comment('ЛПР: должность или роль в закупке (историческое поле)');
            $table->string('decision_maker_contact', 255)->nullable()
                ->comment('ЛПР: телефон, почта, мессенджер (историческое поле)');
            $table->date('decision_maker_birthday')->nullable()
                ->comment('ЛПР: день рождения (историческое поле)');
        });
    }
};
