<?php

namespace App\Services\Payroll\Components;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * Бонус за новых клиентов — развитие базы, отдельно от её удержания.
 *
 * Новый клиент — партнёр с первой в истории отгрузкой в этом месяце. Половина
 * бонуса — сразу, вторая половина — когда он повторит закупку в отведённый срок:
 * разовый покупатель «для галочки» приносит половину, настоящий новый — всё.
 * Возвращённый (спящий дольше порога и снова купивший) — с весом.
 * Порог суммы первой отгрузки отсекает тестовые заказы; потолок на месяц —
 * от накрутки. Лиды сюда не входят: платим за конверсию, а не за карточки.
 */
class NewClientsBonusComponent extends AbstractComponent
{
    public function key(): string
    {
        return 'new_clients_bonus';
    }

    public function label(): string
    {
        return 'Новые клиенты';
    }

    public function description(): string
    {
        return 'Бонус за партнёров, которые в этом месяце сделали первую в истории закупку, и за вернувшихся после долгого перерыва. Половина — за первую отгрузку, половина — когда новый клиент повторит закупку в отведённый срок.';
    }

    public function howComputed(): string
    {
        return 'За каждого нового клиента с первой отгрузкой не ниже порога — половина бонуса сейчас и половина при повторной отгрузке в срок; за возвращённого — бонус × вес. Сумма за месяц не выше потолка.';
    }

    public function kind(): ComponentKind
    {
        return ComponentKind::AMOUNT;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bonus' => ['type' => 'number', 'minimum' => 0, 'title' => 'Бонус за нового клиента, ₽'],
                'min_first_amount' => ['type' => 'number', 'minimum' => 0, 'title' => 'Минимальная сумма первой отгрузки, ₽'],
                'repeat_within_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'title' => 'Повторная закупка в течение, дней'],
                'monthly_cap' => ['type' => 'number', 'minimum' => 0, 'title' => 'Потолок за месяц, ₽ (0 — без потолка)'],
                'returned_weight' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Вес возвращённого клиента (0–1)'],
                'returned_after_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'title' => 'Возвращённый — после паузы дольше, дней'],
            ],
            'required' => ['bonus', 'min_first_amount', 'repeat_within_days', 'monthly_cap', 'returned_weight', 'returned_after_days'],
            'additionalProperties' => false,
        ];
    }

    public function defaults(): array
    {
        return [
            'bonus' => 0,
            'min_first_amount' => 0,
            'repeat_within_days' => 60,
            'monthly_cap' => 0,
            'returned_weight' => 0.5,
            'returned_after_days' => (int) config('crm.lifecycle.sleeping_after_days', 90),
        ];
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $bonus = $this->number($params, 'bonus');
        $minFirst = $this->number($params, 'min_first_amount');
        $cap = $this->number($params, 'monthly_cap');
        $returnedWeight = max(0.0, min(1.0, $this->number($params, 'returned_weight', 0.5)));

        $total = 0.0;
        $evidence = [];
        $counts = ['new' => 0, 'repeat' => 0, 'returned' => 0, 'below_min' => 0];

        foreach ($context->inputs->newClients as $client) {
            $kind = (string) ($client['kind'] ?? 'new');
            $stage = (string) ($client['stage'] ?? 'first');
            $firstAmount = (float) ($client['first_amount'] ?? 0.0);

            $amount = 0.0;
            $reason = '';

            if ($kind === 'returned') {
                $amount = $bonus * $returnedWeight;
                $reason = sprintf('вернулся после паузы %d дн. — бонус × %s', (int) ($client['gap_days'] ?? 0), Money::factor($returnedWeight));
                $counts['returned']++;
            } elseif ($firstAmount + 0.005 < $minFirst) {
                $reason = sprintf('первая отгрузка %s ниже порога %s', Money::rub($firstAmount), Money::rub($minFirst));
                $counts['below_min']++;
            } elseif ($stage === 'repeat') {
                $amount = $bonus / 2;
                $reason = sprintf('повторная закупка через %d дн. — вторая половина', (int) ($client['repeat_after_days'] ?? 0));
                $counts['repeat']++;
            } else {
                $amount = $bonus / 2;
                $reason = 'первая отгрузка — половина бонуса, вторая при повторе';
                $counts['new']++;
            }

            $amount = Money::round($amount);
            $total += $amount;

            $evidence[] = $client + ['amount' => $amount, 'reason' => $reason];
        }

        $capped = $cap > 0 && $total > $cap;
        $total = Money::round($capped ? $cap : $total);

        $parts = [];
        if ($counts['new'] > 0) {
            $parts[] = sprintf('новых %d', $counts['new']);
        }
        if ($counts['repeat'] > 0) {
            $parts[] = sprintf('повторов %d', $counts['repeat']);
        }
        if ($counts['returned'] > 0) {
            $parts[] = sprintf('вернувшихся %d', $counts['returned']);
        }
        if ($counts['below_min'] > 0) {
            $parts[] = sprintf('ниже порога %d', $counts['below_min']);
        }

        $explanation = $evidence === []
            ? 'Новых и вернувшихся клиентов в этом месяце нет'
            : sprintf(
                'Бонус %s (%s; за клиента %s)%s',
                Money::rub($total),
                implode(', ', $parts),
                Money::rub($bonus),
                $capped ? sprintf(' — потолок %s', Money::rub($cap)) : '',
            );

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $total,
            explanation: $explanation,
            evidence: $evidence,
            meta: $counts + ['bonus' => $bonus, 'cap' => $cap, 'capped' => $capped],
        );
    }
}
