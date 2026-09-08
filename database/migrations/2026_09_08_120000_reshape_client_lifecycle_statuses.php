<?php

use App\Enums\Crm\ClientLifecycleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Наведение порядка в стадиях партнёра (решение РОПа 08.09.2026).
 *
 * Причина ухода стала отдельной стадией: «Ушёл к конкуренту», «Закрылся»,
 * «Банкрот». Раньше все они сливались в один «Ушёл», и понять по списку,
 * с кем работать дальше, было нельзя.
 *
 * Снято:
 *   - `hopeless` («Непреодолимо») — подпись приехала из таблицы «План 2026»,
 *     и её значения не знал никто, включая руководителя отдела. Четыре носителя
 *     разобраны вручную, остальные (если появятся на другом стенде) уезжают
 *     в общий «Ушёл».
 *   - `closing` («Закрывается») — путалось с новым «Закрылся», хотя означало
 *     обратное: партнёр ещё наш. Стало «Риск ухода» (`at_risk`).
 *
 * Журнал `crm_client_status_changes` не переписывается намеренно: там записано,
 * что человек выбрал в тот день, и подменять историю новыми словами нельзя.
 * Подписи снятых значений живут в ClientLifecycleStatus::RETIRED.
 */
return new class extends Migration
{
    /** Снятая стадия → действующая. */
    private const MOVES = [
        'hopeless' => 'churned',
        'closing' => 'at_risk',
    ];

    public function up(): void
    {
        foreach (self::MOVES as $from => $to) {
            DB::table('crm_client_profiles')->where('lifecycle_status', $from)->update([
                'lifecycle_status' => $to,
                'updated_at' => now(),
            ]);

            // Подсказка ночной команды хранит те же значения — снятая стадия
            // в ней означала бы предложение «перейти в то, чего нет».
            DB::table('crm_client_profiles')->where('lifecycle_hint', $from)->update([
                'lifecycle_hint' => $to,
                'updated_at' => now(),
            ]);
        }

        $this->comment(
            "Стадия работы: 'lead' — лид, 'in_work' — в работе, 'active' — активен, "
            ."'sleeping' — спящий, 'at_risk' — риск ухода, 'competitor' — ушёл к конкуренту, "
            ."'closed' — закрылся, 'bankrupt' — банкрот, 'churned' — ушёл (причина не выяснена)",
            'Предлагаемая системой стадия (значения те же, что у lifecycle_status); NULL — предложений нет',
        );
    }

    public function down(): void
    {
        foreach (self::MOVES as $from => $to) {
            DB::table('crm_client_profiles')->where('lifecycle_status', $to)->update([
                'lifecycle_status' => $from,
                'updated_at' => now(),
            ]);
            DB::table('crm_client_profiles')->where('lifecycle_hint', $to)->update([
                'lifecycle_hint' => $from,
                'updated_at' => now(),
            ]);
        }

        $this->comment(
            "Стадия работы: 'lead' — лид, 'in_work' — в работе, 'active' — активен, 'sleeping' — спящий, "
            ."'closing' — закрывается, 'churned' — ушёл, 'hopeless' — непреодолимо",
            'Предлагаемый системой статус (значения те же, что у lifecycle_status); NULL — предложений нет',
        );
    }

    /**
     * Комментарии колонок. SQLite в тестах ->change() по строковым колонкам
     * не поддерживает, а комментариев там нет вовсе — правим только MySQL.
     */
    private function comment(string $status, string $hint): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('crm_client_profiles', function (Blueprint $table) use ($status, $hint) {
            $table->string('lifecycle_status', 20)
                ->default(ClientLifecycleStatus::ACTIVE->value)
                ->comment($status)
                ->change();

            $table->string('lifecycle_hint', 20)
                ->nullable()
                ->comment($hint)
                ->change();
        });
    }
};
