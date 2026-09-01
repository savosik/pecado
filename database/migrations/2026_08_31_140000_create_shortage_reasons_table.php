<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник причин недоборов вместо зашитой в код пары «склад / клиент».
 *
 * Метку отмены раньше держало перечисление `App\Enums\Order\CancelSource`
 * с двумя значениями. Отдел продаж разбирает недоборы девятью причинами, и
 * список будет меняться дальше: «увели из-под резерва», «ошибка учёта в 1С» —
 * это рабочие формулировки РОПа, а не сущности разработчика. Причина становится
 * строкой справочника; категория причины остаётся перечислением в коде
 * (`App\Enums\Shortage\ShortageReasonCategory`) — на ней держатся чипы и сводки.
 *
 * Старая разметка переносится в заводские причины: `warehouse` — в недостачу
 * склада, `client` — в отказ клиента через менеджера (именно так метка «клиент»
 * и описывалась в разделе). Колонка `cancel_source` после переноса удаляется:
 * двух правд о причине отмены быть не должно.
 */
return new class extends Migration
{
    /**
     * Заводской набор: то, чем отдел разбирает недоборы прямо сейчас.
     *
     * @var list<array{name: string, description: string, category: string, sort_order: int}>
     */
    private const DEFAULTS = [
        [
            'name' => 'Нет остатка при получении с сайта',
            'description' => 'Сайт принял заказ на остаток, которого к моменту обработки уже не было.',
            'category' => 'stock',
            'sort_order' => 10,
        ],
        [
            'name' => 'Увели из-под резерва позицию',
            'description' => 'Товар был зарезервирован под заказ, но ушёл другому документу.',
            'category' => 'stock',
            'sort_order' => 20,
        ],
        [
            'name' => 'Товар не снабжён предзаказом',
            'description' => 'Позицию нечем было обеспечить: заказ поставщику не размещён или не пришёл в срок.',
            'category' => 'supply',
            'sort_order' => 30,
        ],
        [
            'name' => 'Отменил склад по причине недостачи',
            'description' => 'При сборке товара физически не хватило — пересчёт разошёлся с учётом.',
            'category' => 'warehouse',
            'sort_order' => 40,
        ],
        [
            'name' => 'Отменил склад по причине дефектов',
            'description' => 'Товар на складе есть, но он мятый, вскрытый или бракованный — отгружать нельзя.',
            'category' => 'warehouse',
            'sort_order' => 50,
        ],
        [
            'name' => 'Отменил менеджер по просьбе клиента',
            'description' => 'Клиент попросил убрать позицию из заказа, менеджер снял её в 1С.',
            'category' => 'client',
            'sort_order' => 60,
        ],
        [
            'name' => 'Отменил клиент после сборки заказа',
            'description' => 'Заказ был собран, но клиент отказался от позиции уже после сборки.',
            'category' => 'client',
            'sort_order' => 70,
        ],
        [
            'name' => 'Отменил менеджер вручную сам',
            'description' => 'Решение менеджера без просьбы клиента: замена, перенос в другой заказ, ошибка ввода.',
            'category' => 'manager',
            'sort_order' => 80,
        ],
        [
            'name' => 'Ошибка учёта в 1С',
            'description' => 'Строка отменена из-за расхождения в данных, а не из-за товара.',
            'category' => 'accounting',
            'sort_order' => 90,
        ],
    ];

    /** Старая метка → заводская причина, в которую она переносится. */
    private const SOURCE_MAP = [
        'warehouse' => 'Отменил склад по причине недостачи',
        'client' => 'Отменил менеджер по просьбе клиента',
    ];

    public function up(): void
    {
        Schema::create('shortage_reasons', function (Blueprint $table) {
            $table->comment('Справочник причин недоборов: строки ведёт руководитель отдела продаж, категория причины — перечисление в коде');

            $table->id()->comment('Первичный ключ');
            $table->string('name', 191)->comment('Формулировка причины для выпадающего списка: «Отменил склад по причине недостачи»');
            $table->string('description', 500)->nullable()->comment('Пояснение для легенды: когда менеджеру выбирать именно эту причину');
            $table->string('category', 20)
                ->comment("Категория (зона ответственности): 'stock' — остатки и резерв, 'supply' — снабжение, 'warehouse' — склад, 'client' — клиент, 'manager' — менеджер, 'accounting' — учёт 1С");
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Порядок в выпадающем списке и легенде');
            $table->boolean('is_active')->default(true)->comment('Показывать ли причину в выпадающем списке; неактивная остаётся в сводках и в уже размеченных строках');
            $table->boolean('is_system')->default(false)->comment('Заводская причина: удалить нельзя (на неё ссылается перенос старой разметки), отключить можно');

            $table->timestamp('created_at')->nullable()->comment('Когда причина заведена');
            $table->timestamp('updated_at')->nullable()->comment('Когда причина изменена');

            $table->unique('name', 'shortage_reasons_name_unique');
            $table->index(['is_active', 'sort_order'], 'shortage_reasons_active_order_index');
            $table->index('category', 'shortage_reasons_category_index');
        });

        $now = now();

        DB::table('shortage_reasons')->insert(array_map(fn (array $reason) => [
            ...$reason,
            'is_active' => true,
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::DEFAULTS));

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('cancel_reason_id')->nullable()->after('cancelled_at')
                ->comment('Причина недобора (shortage_reasons.id); NULL — отмена ещё не разобрана менеджером')
                ->constrained('shortage_reasons')
                ->restrictOnDelete();
        });

        // Перенос старой разметки. Кто и когда её поставил (cancel_source_user_id,
        // cancel_source_at) остаётся на месте — меняется только сама метка.
        foreach (self::SOURCE_MAP as $source => $reasonName) {
            $reasonId = DB::table('shortage_reasons')->where('name', $reasonName)->value('id');

            if ($reasonId === null) {
                continue;
            }

            DB::table('order_items')
                ->where('cancel_source', $source)
                ->update(['cancel_reason_id' => $reasonId]);
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_cancel_source_index');
            $table->dropColumn('cancel_source');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('cancel_source', 20)->nullable()->after('cancelled_at')
                ->comment("Кто отменил строку: 'warehouse' — склад при сборке, 'client' — отказ клиента; NULL — не размечено");
            $table->index('cancel_source', 'order_items_cancel_source_index');
        });

        // Обратный перенос — по категории причины: точнее исходную пару значений
        // не восстановить, а категории как раз и были её расширением.
        DB::table('order_items')
            ->whereIn('cancel_reason_id', DB::table('shortage_reasons')->whereIn('category', ['warehouse', 'stock', 'supply', 'accounting'])->pluck('id'))
            ->update(['cancel_source' => 'warehouse']);

        DB::table('order_items')
            ->whereIn('cancel_reason_id', DB::table('shortage_reasons')->whereIn('category', ['client', 'manager'])->pluck('id'))
            ->update(['cancel_source' => 'client']);

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_reason_id');
        });

        Schema::dropIfExists('shortage_reasons');
    }
};
