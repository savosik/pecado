<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обмер грузовых мест расходного ордера (протокол v16.14.0, топик Agent Hub №13).
 *
 * Упаковщик в РМУ 1С меряет каждую коробку при её закрытии; 1С присылает вес и габариты
 * в `packages[]`, а в шапке — состояние обмера (`measurement`), способ доставки
 * (`shipping_mode`) и номер ревизии (`revision`).
 *
 * Места с v16.14.0 сверяются по `uuid` (GUID документа УпаковочныйЛист), а не удаляются
 * и вставляются заново, поэтому уникальность номера в пределах ордера снимается: номер —
 * только отображение, 1С вправе перенумеровать листы, и перестановка двух номеров в одном
 * снимке упиралась бы в уникальный индекс на середине обновления.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_issues', function (Blueprint $table) {
            $table->unsignedBigInteger('applied_revision')->nullable()->after('uuid')
                ->comment('Последняя применённая ревизия ордера из 1С (v16.14.0). NULL — ревизий не приходило (старый формат). Сообщения с revision меньше или равной, а после первой ревизии и без revision — отбрасываются');
            $table->string('shipping_mode', 16)->nullable()->after('delivery_order')
                ->comment("Способ доставки ордера по версии 1С (v16.14.0): 'pickup' — все распоряжения самовывоз, 'delivery' — все доставка, 'mixed' — сочетание, NULL — не определён или ордер старого формата");
            $table->boolean('measurement_required')->nullable()->after('shipping_mode')
                ->comment('Нужен ли обмер мест по мнению 1С (v16.14.0). NULL — ордер старого формата, блока measurement не было');
            $table->string('measurement_state', 16)->nullable()->after('measurement_required')
                ->comment("Состояние обмера мест (v16.14.0): 'not_required' — не нужен (самовывоз, отгружен без товара), 'pending' — нужен, не завершён или сброшен, 'done' — завершён упаковщиком. NULL — ордер старого формата");
            $table->timestamp('measured_at')->nullable()->after('measurement_state')
                ->comment('Момент завершения обмера всего ордера (v16.14.0). Заполнен только при measurement_state = done');
            $table->string('measured_by')->nullable()->after('measured_at')
                ->comment('Кто завершил обмер — ФИО упаковщика строкой из 1С (v16.14.0). Заполнено только при measurement_state = done');

            $table->index('measurement_state', 'goods_issues_measurement_state_index');
        });

        // Сначала обычный индекс, потом снятие уникального: MySQL держит внешний ключ
        // goods_issue_id на уникальном индексе и не даст его удалить без замены.
        Schema::table('goods_issue_packages', function (Blueprint $table) {
            $table->index(['goods_issue_id', 'number'], 'goods_issue_packages_issue_number_index');
        });

        Schema::table('goods_issue_packages', function (Blueprint $table) {
            $table->dropUnique('goods_issue_packages_unique');
        });

        Schema::table('goods_issue_packages', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('goods_issue_id')
                ->comment('GUID документа УпаковочныйЛист в 1С (v16.14.0) — ключ идентичности места: переживает перепроведение, пересоздание листа даёт новый. NULL — место старого формата');
            $table->string('barcode', 64)->nullable()->after('number')
                ->comment('Штрихкод наклейки места (v16.14.0): Code128, GUID листа как десятичная строка без ведущих нулей, длина переменная. Сравнивается как строка, нулями не дополняется');
            $table->string('package_type', 16)->nullable()->after('barcode')
                ->comment("Тип места (v16.14.0): 'box' — коробка, 'pallet' — паллета, 'bag' — мешок, 'roll' — рулон, 'other' — прочее. NULL — не передан (то же, что other)");
            $table->unsignedInteger('length')->nullable()->after('weight')
                ->comment('Длина места, см, целое — внешний обмер упаковщика, округление вверх (v16.14.0)');
            $table->unsignedInteger('width')->nullable()->after('length')
                ->comment('Ширина места, см, целое — внешний обмер упаковщика, округление вверх (v16.14.0)');
            $table->unsignedInteger('height')->nullable()->after('width')
                ->comment('Высота места, см, целое — внешний обмер упаковщика, округление вверх (v16.14.0)');

            $table->unique(['goods_issue_id', 'uuid'], 'goods_issue_packages_issue_uuid_unique');
            $table->index('barcode', 'goods_issue_packages_barcode_index');
        });
    }

    public function down(): void
    {
        Schema::table('goods_issue_packages', function (Blueprint $table) {
            $table->dropUnique('goods_issue_packages_issue_uuid_unique');
            $table->dropIndex('goods_issue_packages_barcode_index');
            $table->dropColumn(['uuid', 'barcode', 'package_type', 'length', 'width', 'height']);
        });

        Schema::table('goods_issue_packages', function (Blueprint $table) {
            $table->unique(['goods_issue_id', 'number'], 'goods_issue_packages_unique');
        });

        Schema::table('goods_issue_packages', function (Blueprint $table) {
            $table->dropIndex('goods_issue_packages_issue_number_index');
        });

        Schema::table('goods_issues', function (Blueprint $table) {
            $table->dropIndex('goods_issues_measurement_state_index');
            $table->dropColumn([
                'applied_revision', 'shipping_mode', 'measurement_required',
                'measurement_state', 'measured_at', 'measured_by',
            ]);
        });
    }
};
