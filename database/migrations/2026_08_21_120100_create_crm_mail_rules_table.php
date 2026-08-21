<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правило-фильтр над потоком писем — как фильтр в почтовом ящике.
 *
 * «Если письмо содержит инн:7701234567 и метку акт-сверки — отправить на buh@…».
 * Приоритетов и остановки разбора здесь нет намеренно: правила независимы, письмо
 * может подойти под несколько, адрес не дублируется. Исключение выражается условием
 * «не содержит», а не порядком разбора — именно порядок оказался главным источником
 * непонимания в предыдущем подходе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_mail_rules', function (Blueprint $table) {
            $table->comment('Правила-фильтры над потоком писем CRM');

            $table->id()->comment('Первичный ключ');

            $table->string('name')->comment('Название правила глазами менеджера: «Акты Афониной»');

            $table->foreignId('user_id')->nullable()
                ->comment('Кто завёл правило (users.id); NULL — правило пережило удаление автора')
                ->constrained('users')
                ->nullOnDelete();

            $table->json('conditions')->nullable()
                ->comment('Условия отбора: {"all":[{"field":"tag","op":"has_tag","value":"акт-сверки"}]}; NULL — ловит всё');

            $table->json('recipients')
                ->comment('Кому отправлять: список адресов; спецзначения «клиент» и «менеджер» раскрываются по письму');

            $table->json('cc')->nullable()->comment('Копия: список адресов');

            $table->boolean('auto_send')->default(false)
                ->comment('Отправлять автоматически без участия менеджера; выключено — письмо ждёт самолётика с готовыми получателями');

            $table->boolean('is_active')->default(true)
                ->comment('Правило работает; выключенное не участвует в отборе');

            $table->unsignedInteger('throttle_minutes')->nullable()
                ->comment('Не чаще одного автоматического письма на адрес за столько минут; NULL — без ограничения');

            $table->unsignedInteger('matched_count')->default(0)
                ->comment('Сколько писем правило поймало за всё время');

            $table->timestamp('last_matched_at')->nullable()
                ->comment('Когда правило сработало в последний раз; пусто — не ловило ничего');

            $table->timestamp('created_at')->nullable()->comment('Когда правило заведено');
            $table->timestamp('updated_at')->nullable()->comment('Когда правило последний раз изменено');

            $table->index(['is_active', 'auto_send'], 'crm_mail_rules_active_idx');
        });

        Schema::create('crm_mail_rule_hits', function (Blueprint $table) {
            $table->comment('Срабатывания правил: какое правило какое письмо поймало');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('rule_id')
                ->comment('Правило (crm_mail_rules.id)')
                ->constrained('crm_mail_rules')
                ->cascadeOnDelete();

            $table->foreignId('crm_email_id')
                ->comment('Письмо (crm_emails.id)')
                ->constrained('crm_emails')
                ->cascadeOnDelete();

            $table->boolean('auto_sent')->default(false)
                ->comment('Правило не только проставило получателей, но и отправило письмо само');

            $table->timestamp('created_at')->nullable()->comment('Когда правило поймало письмо');

            $table->unique(['rule_id', 'crm_email_id'], 'crm_mail_rule_hits_unique');
            $table->index(['rule_id', 'created_at'], 'crm_mail_rule_hits_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mail_rule_hits');
        Schema::dropIfExists('crm_mail_rules');
    }
};
