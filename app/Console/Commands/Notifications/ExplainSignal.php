<?php

namespace App\Console\Commands\Notifications;

use App\Models\NotificationDelivery;
use App\Models\NotificationSignal;
use Illuminate\Console\Command;

/**
 * Разбор одного сигнала: что пришло, какие правила рассматривались,
 * кому ушло и почему кому-то не ушло.
 */
class ExplainSignal extends Command
{
    protected $signature = 'notifications:explain {uuid : Идентификатор сигнала}';

    protected $description = 'Показать, как пульт разобрал конкретный сигнал';

    public function handle(): int
    {
        $signal = NotificationSignal::where('uuid', $this->argument('uuid'))->first();

        if ($signal === null) {
            $this->error('Сигнал не найден.');

            return self::FAILURE;
        }

        $this->info("Событие: {$signal->event_key}");
        $this->line('Партнёр: '.($signal->client_user_id ?? '—').', контрагент: '.($signal->company_id ?? '—'));
        $this->line('Режим: '.$signal->mode.', совпало правил: '.$signal->matched_rules_count);
        $this->newLine();

        $this->line('<comment>Контекст события</comment>');
        foreach ((array) $signal->data as $key => $value) {
            $this->line(sprintf('  %-24s %s', $key, is_scalar($value) ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE)));
        }

        $this->newLine();
        $this->line('<comment>Метки</comment>');
        $this->line('  '.implode(', ', (array) $signal->tags));

        $this->newLine();
        $this->line('<comment>Доставки</comment>');

        $deliveries = NotificationDelivery::where('signal_uuid', $signal->uuid)->get();

        if ($deliveries->isEmpty()) {
            $this->line('  Ни одного адресата: не совпало ни одно правило либо у правил нет получателей.');

            return self::SUCCESS;
        }

        $this->table(
            ['Адрес', 'Правило', 'Статус', 'Причина'],
            $deliveries->map(fn (NotificationDelivery $d) => [
                $d->recipient,
                $d->rule_name,
                NotificationDelivery::statusLabel($d->status),
                $d->skip_reason ? NotificationDelivery::skipReasonLabel($d->skip_reason) : '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
