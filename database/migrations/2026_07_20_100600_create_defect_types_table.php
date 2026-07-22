<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник типовых дефектов (быстрый выбор при заведении некондиции).
 *
 * Кладовщик тыкает чипы в форме вместо ручного ввода частых формулировок
 * (порвана упаковка, нет крышки батарейного отсека и т.д.). Справочник
 * редактируется закупщиком/админом. Наполняется дефолтными значениями здесь же,
 * чтобы попасть на prod через migrate без ручного seed.
 */
return new class extends Migration
{
    /** @var string[] */
    private const DEFAULTS = [
        'Порвана упаковка',
        'Помята упаковка',
        'Вскрыта упаковка',
        'Нет крышки батарейного отсека',
        'Потёртости на корпусе',
        'Царапины на корпусе',
        'Скол на корпусе',
        'Следы эксплуатации',
        'Некомплект',
        'Загрязнение',
    ];

    public function up(): void
    {
        Schema::create('defect_types', function (Blueprint $table) {
            $table->comment('Справочник типовых дефектов для быстрого выбора при заведении некондиции');

            $table->id()->comment('Первичный ключ');
            $table->string('name')->unique()->comment('Формулировка дефекта, например «Порвана упаковка»');
            $table->boolean('is_active')->default(true)->comment('Показывать ли в форме заведения некондиции');
            $table->unsignedInteger('sort_order')->default(0)->comment('Порядок вывода чипов (по возрастанию)');
            $table->timestamp('created_at')->nullable()->comment('Дата создания');
            $table->timestamp('updated_at')->nullable()->comment('Дата изменения');

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();
        $rows = [];
        foreach (self::DEFAULTS as $i => $name) {
            $rows[] = [
                'name' => $name,
                'is_active' => true,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('defect_types')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('defect_types');
    }
};
