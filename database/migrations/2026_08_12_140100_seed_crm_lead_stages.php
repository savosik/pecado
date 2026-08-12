<?php

use App\Models\CrmLeadStage;
use Illuminate\Database\Migrations\Migration;

/**
 * Стартовый набор стадий воронки лидов.
 *
 * Раздел не должен открываться пустой доской без единой колонки: первое, что
 * увидел бы РОП, — экран, на котором нечего делать, пока он не догадается
 * завести стадии сам. Набор дальше правится через интерфейс.
 *
 * Идемпотентно: если стадии уже есть, миграция ничего не делает.
 */
return new class extends Migration
{
    private const STAGES = [
        ['name' => 'Новый', 'color' => 'gray', 'position' => 1],
        ['name' => 'Квалификация', 'color' => 'blue', 'position' => 2],
        ['name' => 'Переговоры', 'color' => 'purple', 'position' => 3],
        ['name' => 'Счёт выставлен', 'color' => 'orange', 'position' => 4],
        ['name' => 'Выиграли', 'color' => 'green', 'position' => 5, 'is_won' => true],
        ['name' => 'Проиграли', 'color' => 'red', 'position' => 6, 'is_lost' => true],
    ];

    public function up(): void
    {
        if (CrmLeadStage::query()->exists()) {
            return;
        }

        foreach (self::STAGES as $stage) {
            CrmLeadStage::create($stage);
        }
    }

    public function down(): void
    {
        CrmLeadStage::query()->whereIn('name', array_column(self::STAGES, 'name'))->delete();
    }
};
