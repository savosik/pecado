<?php

namespace App\Console\Commands\Crm;

use App\Models\CrmMailRule;
use App\Services\Crm\Mail\MailRuleEngine;
use Illuminate\Console\Command;

/**
 * Применить все активные правила к уже собранным письмам.
 *
 * То же, что кнопка «применить к старым» у каждого правила, но разом. Нужно
 * после правок «мимо интерфейса» и разовых разборов; в обычной жизни правило
 * работает вперёд, и команда не требуется.
 *
 * Отправкой не распоряжается: её решает галочка на правиле и общий рубильник.
 */
class MailReapplyRules extends Command
{
    protected $signature = 'mail:reapply-rules';

    protected $description = 'Перепроверить неотправленные письма всеми активными правилами';

    public function handle(MailRuleEngine $engine): int
    {
        $rules = CrmMailRule::query()->active()->orderBy('id')->get();

        if ($rules->isEmpty()) {
            $this->info('Активных правил нет — перепроверять нечем.');

            return self::SUCCESS;
        }

        foreach ($rules as $rule) {
            $picked = $engine->applyToOld($rule);

            $this->line(sprintf('  «%s» — подобрано писем: %d', $rule->name, $picked));
        }

        return self::SUCCESS;
    }
}
