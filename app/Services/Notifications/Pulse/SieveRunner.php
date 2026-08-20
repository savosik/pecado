<?php

namespace App\Services\Notifications\Pulse;

use App\Models\NotificationRule;
use App\Notifications\Pulse\Support\PulseSignal;

/**
 * Разбор правил в порядке приоритета — как фильтры в почте.
 *
 * Ключевая деталь семантики: получатели правила применяются ДО остановки.
 * «Не обрабатывать дальше» означает «сделай своё действие и не смотри ниже»,
 * а не «ничего не делай». Именно это позволяет выразить кейс заказчика:
 * закрытие заказа уходит директору и не уходит клиенту, а остальные статусы
 * падают на правило ниже.
 */
class SieveRunner
{
    public function __construct(
        private readonly RuleMatcher $matcher,
        private readonly ConditionEvaluator $evaluator,
        private readonly ConditionValidator $validator,
        private readonly RecipientResolver $resolver,
    ) {}

    /**
     * @param  array<int, string>  $tags
     * @return array{recipients: array<int, ResolvedRecipient>, matched: array<int, NotificationRule>, trace: array<int, array<string, mixed>>}
     */
    public function run(PulseSignal $signal, array $tags = []): array
    {
        $bag = new RecipientBag;
        $matched = [];
        $trace = [];

        foreach ($this->matcher->rulesFor($signal) as $rule) {
            // Правило со сломанным условием не срабатывает наугад: оно
            // не совпадает вовсе и видно в трассе как требующее внимания.
            if (! $this->validator->passes($rule->conditions, $rule->event_key)) {
                $trace[] = $this->traceEntry($rule, false, 'условие правила некорректно');

                continue;
            }

            if (! $this->evaluator->matches($rule->conditions, $signal->data, $tags)) {
                $trace[] = $this->traceEntry($rule, false, 'условия не выполнены');

                continue;
            }

            $rule->registerMatch();
            $matched[] = $rule;

            $resolved = $this->resolver->resolve($rule, $signal);
            $bag->apply($rule, $resolved);

            $trace[] = $this->traceEntry($rule, true, null, count($resolved));

            if ($rule->stop_processing) {
                $trace[] = [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'stopped_here' => true,
                    'note' => 'Правило остановило дальнейший разбор',
                ];

                break;
            }
        }

        return [
            'recipients' => $bag->all(),
            'matched' => $matched,
            'trace' => $trace,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function traceEntry(NotificationRule $rule, bool $matched, ?string $note = null, int $resolvedCount = 0): array
    {
        return [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'priority' => $rule->priority,
            'matched' => $matched,
            'note' => $note,
            'resolved_count' => $resolvedCount,
            'stop_processing' => $rule->stop_processing,
        ];
    }
}
