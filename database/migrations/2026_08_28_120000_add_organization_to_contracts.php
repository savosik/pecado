<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Договор — между двумя сторонами. Вторая (контрагент) была, первой (наше
 * юрлицо) не было: она угадывалась по категории-вкладке, а категория —
 * внутренняя папка менеджеров, партнёру её показывать нельзя.
 *
 * Разнос по существующим договорам делается здесь по организации категории;
 * категории без организации (ИП Кербер, пока не заведён в справочнике)
 * дозаполняются позже командой crm:contracts-assign-organizations —
 * после того как РОП привяжет организацию к категории.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('category_id')
                ->comment('Наше юрлицо — первая сторона договора (organizations.id); NULL — ещё не разнесён')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'contracts_organization_idx');
        });

        // «ООО Пекадо Импорт» — закупочный контур того же ООО «Пекадо»: юрлицо
        // одно, договоры в нём другие (мы покупаем, а не отгружаем). Категория
        // была заведена без организации — привязываем к той же, что у «ООО Пекадо».
        $pecado = DB::table('contract_categories')->where('name', 'ООО Пекадо')->value('organization_id');

        if ($pecado !== null) {
            DB::table('contract_categories')
                ->where('name', 'ООО Пекадо Импорт')
                ->whereNull('organization_id')
                ->update(['organization_id' => $pecado]);
        }

        // По категориям, а не UPDATE … JOIN: тесты идут на SQLite, где такого нет.
        DB::table('contract_categories')
            ->whereNotNull('organization_id')
            ->get(['id', 'organization_id'])
            ->each(fn ($category) => DB::table('contracts')
                ->where('category_id', $category->id)
                ->whereNull('organization_id')
                ->update(['organization_id' => $category->organization_id]));
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex('contracts_organization_idx');
            $table->dropColumn('organization_id');
        });
    }
};
