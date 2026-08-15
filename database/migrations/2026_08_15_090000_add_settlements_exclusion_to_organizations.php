<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Исключение внутренних организаций из взаиморасчётов с партнёрами и контрагентами.
 *
 * В 1С существует техническая организация «Реклама» (f3070b58-327d-11e4-ac24-001e6711ed1d):
 * на неё проводятся внутренние рекламные операции, к расчётам с клиентами они отношения
 * не имеют. Регистр взаиморасчётов присылал её движения наравне с остальными, и «Реклама»
 * всплывала в акте сверки, календаре оплат, реквизитах долга и балансах.
 *
 * Флаг ставится миграцией, а не руками в админке: исключение должно приехать на dev
 * и прод вместе с фильтром на входе, иначе между деплоем и ручной правкой данные
 * снова засорятся.
 *
 * Уже принятые данные вычищаются здесь же: фильтр в обработчиках защищает только
 * будущие сообщения. Данные восстановимы — сообщения регистра идут полной заменой,
 * и 1С досылает историю по запросу.
 */
return new class extends Migration
{
    /** UUID организации «Реклама» в 1С. */
    private const ADVERTISING_ORGANIZATION_UUID = 'f3070b58-327d-11e4-ac24-001e6711ed1d';

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_settlements_excluded')->default(false)->after('is_stub')
                ->comment('Исключена из взаиморасчётов: движения регистра, контрольные точки и балансы этой организации не принимаются с шины (внутренние юрлица вроде «Рекламы»)');
        });

        DB::table('organizations')
            ->where('external_id', self::ADVERTISING_ORGANIZATION_UUID)
            ->update(['is_settlements_excluded' => true]);

        $excluded = DB::table('organizations')
            ->where('is_settlements_excluded', true)
            ->pluck('id');

        if ($excluded->isEmpty()) {
            return;
        }

        DB::table('settlement_entries')->whereIn('organization_id', $excluded)->delete();
        DB::table('settlement_checkpoints')->whereIn('organization_id', $excluded)->delete();
        DB::table('contractor_organization_balances')->whereIn('organization_id', $excluded)->delete();
        DB::table('contractor_balance_overdue_details')->whereIn('organization_id', $excluded)->delete();
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('is_settlements_excluded');
        });
    }
};
