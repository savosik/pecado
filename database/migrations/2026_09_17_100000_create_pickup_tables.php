<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Самовывоз (эпик pick-00): пропуска курьерам и факт выдачи заказов на складе.
 *
 * Документы сайта: в 1С статуса «выдан» нет и не будет, по шине эти таблицы не ходят.
 * Единица выдачи — расходный ордер (физический комплект коробок).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_passes', function (Blueprint $table) {
            $table->comment('Пропуска на самовывоз: клиент выпускает в кабинете и пересылает курьеру (QR и код)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Клиент — владелец пропуска (users.id)')->constrained()->cascadeOnDelete();
            $table->string('scope', 16)->comment("Охват: 'all' — всё готовое на момент скана, 'selected' — выбранные ордера (pickup_pass_items)");
            $table->char('token_hash', 64)->unique()->comment('sha256 от секретного токена ссылки /p/{token}; сам токен не хранится');
            $table->text('token_encrypted')->comment('Тот же токен, зашифрованный ключом приложения: чтобы владелец мог снова открыть QR и ссылку в кабинете');
            $table->char('code', 6)->index()->comment('Шестизначный код для ввода вручную; уникален среди действующих пропусков');
            $table->string('status', 16)->default('active')->index()->comment("Статус: 'active' — действует, 'used' — всё выдано, 'revoked' — отозван клиентом, 'expired' — истёк срок");
            $table->timestamp('expires_at')->comment('Срок действия — конец N-го рабочего дня склада');
            $table->string('courier_name')->nullable()->comment('Имя курьера, если клиент указал');
            $table->string('courier_phone', 32)->nullable()->comment('Телефон курьера, если клиент указал');
            $table->string('note', 500)->nullable()->comment('Комментарий клиента для склада');
            $table->string('source', 16)->default('cabinet')->comment("Откуда выпущен: 'cabinet' — кабинет, 'api' — клиентский API или MCP");
            $table->timestamp('used_at')->nullable()->comment('Когда по пропуску выдан последний комплект');
            $table->timestamp('revoked_at')->nullable()->comment('Когда клиент отозвал пропуск');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('pickup_pass_items', function (Blueprint $table) {
            $table->comment('Состав пропуска с охватом selected: какие расходные ордера разрешено выдать');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('pickup_pass_id')->comment('Пропуск (pickup_passes.id)')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_issue_id')->comment('Расходный ордер (goods_issues.id)')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['pickup_pass_id', 'goods_issue_id']);
        });

        Schema::create('pickup_handovers', function (Blueprint $table) {
            $table->comment('Факт выдачи расходного ордера курьеру на складе (отметка «выдан», которой нет в 1С)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('goods_issue_id')->comment('Выданный расходный ордер (goods_issues.id)')->constrained()->cascadeOnDelete();
            $table->foreignId('pickup_pass_id')->nullable()->comment('Пропуск, по которому выдано (pickup_passes.id); пусто — выдача без пропуска')->constrained()->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->comment('Кладовщик, отметивший выдачу (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->comment('Момент выдачи');
            $table->string('method', 16)->comment("Способ: 'qr' — скан пропуска, 'code' — ввод кода, 'barcode' — скан расходного листа, 'manual' — поиск вручную, 'backfill' — закрыто без выдачи (хвост до запуска)");
            $table->string('recipient_name')->nullable()->comment('Кому выдано: имя курьера');
            $table->unsignedInteger('packages_count')->default(0)->comment('Число выданных мест на момент выдачи');
            $table->boolean('box_verified')->default(false)->comment('Коробка проверена сканом штрихкода расходного листа');
            $table->string('comment', 500)->nullable()->comment('Комментарий кладовщика');
            $table->timestamp('cancelled_at')->nullable()->comment('Когда ошибочная выдача отменена');
            $table->foreignId('cancelled_by')->nullable()->comment('Кто отменил выдачу (users.id)')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->comment('Причина отмены выдачи');
            $table->boolean('needs_review')->default(false)->index()->comment('Ордер откатился в 1С после выдачи — требуется разбор');
            $table->string('review_note', 500)->nullable()->comment('Итог разбора расхождения');
            $table->timestamp('reviewed_at')->nullable()->comment('Когда расхождение разобрано');
            // Генерируемая колонка: уникальность только среди неотменённых выдач — защита от двойной выдачи.
            $table->unsignedBigInteger('active_key')->nullable()
                ->virtualAs('CASE WHEN cancelled_at IS NULL THEN goods_issue_id ELSE NULL END');
            $table->timestamps();
            $table->unique('active_key');
            $table->index('issued_at');
        });

        Schema::create('pickup_scan_misses', function (Blueprint $table) {
            $table->comment('Нераспознанные сканы на экране выдачи: сырьё для подтверждения формата штрихкода 1С и контроль перебора кодов');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->nullable()->comment('Кто сканировал (users.id)')->constrained()->nullOnDelete();
            $table->string('raw', 512)->comment('Сырое значение скана или ввода');
            $table->string('kind', 16)->default('scan')->comment("Что это было: 'scan' — камера или сканер, 'code' — ручной ввод кода");
            $table->string('context', 32)->nullable()->comment('Экран, с которого пришёл скан');
            $table->timestamp('created_at')->nullable()->index()->comment('Когда отсканировано');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_scan_misses');
        Schema::dropIfExists('pickup_handovers');
        Schema::dropIfExists('pickup_pass_items');
        Schema::dropIfExists('pickup_passes');
    }
};
