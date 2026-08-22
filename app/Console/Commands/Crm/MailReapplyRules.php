<?php

namespace App\Console\Commands\Crm;

use App\Models\CrmMailRule;
use App\Services\Crm\Mail\MailRuleEngine;
use Illuminate\Console\Command;

/**
 * Прогнать активные правила по письмам, которые ещё не ушли.
 *
 * Нужно после правки самих правил «мимо интерфейса» и один раз после релиза,
 * который меняет разбор: обычно правило подбирает старые письма само, в момент
 * сохранения, но релиз ничего не сохраняет.
 *
 * Ничего не отправляет: автоотправку решает галочка на правиле и общий
 * рубильник, а не эта команда.
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
            $picked = $engine->reapplyToPending($rule);

            $this->line(sprintf('  «%s» — подобрано писем: %d', $rule->name, $picked));
        }

        return self::SUCCESS;
    }
}
