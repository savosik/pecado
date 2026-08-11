<?php

namespace App\Console\Commands;

use App\Models\ErpBusMessage;
use App\Models\Order;
use App\Services\Erp\ErpHandlerOutcome;
use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use Illuminate\Console\Command;

/**
 * v15.4: Восстановление заказов, потерянных из-за неприсланного order.created.
 *
 * До v15.4 order.updated по несуществующему заказу молча игнорировался, поэтому
 * заказы, по которым 1С не прислала order.created, на сайт не попали. Команда
 * находит их в логе шины (erp_bus_messages) и достраивает из последнего
 * известного order.updated.
 *
 * Восстановление идёт через HandleOrderUpdated — тот же путь, что и в проде,
 * так что результат идентичен штатному самовосстановлению.
 *
 * Работает только по тому, что сохранил ErpBusLogger: заказы старше глубины
 * лога восстановить нельзя — их придётся переотправлять из 1С.
 *
 * С августа 2026 глубина лога в БД — ERP_BUS_RETENTION_DAYS (по умолчанию 14
 * дней), всё старше лежит в холодном хранилище архивом `.jsonl.gz` (см.
 * CleanupErpBusMessages). Чтобы восстановить заказ той поры, архив нужного дня
 * придётся сначала скачать и залить обратно — команда читает только таблицу.
 */
class RecoverLostOrdersFromErpBus extends Command
{
    protected $signature = 'erp:recover-lost-orders
        {--dry-run : Показать, что будет восстановлено, ничего не меняя}
        {--numbers= : Только указанные номера 1С через запятую (например 29УТ-010318,29УТ-010319)}';

    protected $description = 'Восстановить заказы, по которым 1С не прислала order.created (из лога шины ERP)';

    public function handle(HandleOrderUpdated $handler, ErpHandlerOutcome $outcome): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('numbers'))));

        $this->info('Сканирую лог шины ERP…');

        $lost = $this->findLostOrders($only);

        if ($lost === []) {
            $this->info('Потерянных заказов не найдено.');

            return self::SUCCESS;
        }

        // Восстановимые — те, где есть partner_uuid и непустые items.
        // Критерий дублирует HandleOrderUpdated намеренно: нужен для предпросмотра
        // в --dry-run. Источник истины — сам handler, он же и отклонит остальные.
        $recoverable = array_filter($lost, fn ($p) => ! empty($p['partner_uuid']) && ! empty($p['items']));
        $unrecoverable = array_diff_key($lost, $recoverable);

        $this->newLine();
        $this->line(sprintf('Найдено потерянных заказов: <options=bold>%d</>', count($lost)));
        $this->line(sprintf('  восстановимых: <fg=green>%d</>', count($recoverable)));
        $this->line(sprintf('  без данных для восстановления: <fg=yellow>%d</>', count($unrecoverable)));
        $this->newLine();

        if ($recoverable !== []) {
            $this->table(
                ['Номер 1С', 'UUID', 'Статус', 'Позиций', 'Создан в 1С'],
                array_map(fn ($p) => [
                    $p['number'] ?? '—',
                    $p['uuid'],
                    $p['status'] ?? '—',
                    count($p['items']),
                    $p['erp_created_at'] ?? '—',
                ], array_values($recoverable)),
            );
        }

        if ($unrecoverable !== []) {
            $this->warn('Не будут восстановлены (нет partner_uuid или позиций) — заводить из 1С вручную:');
            foreach ($unrecoverable as $p) {
                $this->line(sprintf(
                    '  %s (uuid %s, статус %s, позиций %d)',
                    $p['number'] ?? '—',
                    $p['uuid'],
                    $p['status'] ?? '—',
                    count($p['items'] ?? []),
                ));
            }
            $this->newLine();
        }

        if ($dryRun) {
            $this->comment('Пробный прогон (--dry-run): ничего не изменено.');

            return self::SUCCESS;
        }

        if ($recoverable === []) {
            return self::SUCCESS;
        }

        if (! $this->confirm(sprintf('Восстановить %d заказ(ов)?', count($recoverable)), false)) {
            $this->comment('Отменено.');

            return self::SUCCESS;
        }

        $recovered = 0;
        $failed = 0;

        foreach ($recoverable as $payload) {
            $number = $payload['number'] ?? $payload['uuid'];

            try {
                $outcome->reset();
                $handler->handle($payload);

                $order = Order::withTrashed()->where('uuid', $payload['uuid'])->first();

                if (! $order) {
                    $this->error("  {$number}: заказ не появился после обработки");
                    $failed++;

                    continue;
                }

                $recovered++;
                $this->line(sprintf(
                    '  <fg=green>✓</> %s → id=%d, позиций %d, сумма %s',
                    $number,
                    $order->id,
                    $order->items()->count(),
                    number_format((float) $order->total_amount, 2, ',', ' '),
                ));
            } catch (ErpUnprocessableMessageException $e) {
                $this->warn("  {$number}: {$e->getMessage()}");
                $failed++;
            } catch (\Throwable $e) {
                $this->error("  {$number}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Восстановлено: {$recovered}. Не удалось: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Найти заказы, которые 1С обновляла, но на сайте их нет.
     *
     * @param  array<int, string>  $only  Фильтр по номерам 1С (пустой — все)
     * @return array<string, array<string, mixed>> payload последнего order.updated по uuid
     */
    private function findLostOrders(array $only): array
    {
        $latest = [];

        // Лог шины большой (на проде >1 млн строк) — идём чанками.
        // Сообщения упорядочены по id, поэтому последний записанный payload
        // по каждому uuid перетирает предыдущие — он и самый свежий.
        ErpBusMessage::query()
            ->where('event', 'order.updated')
            ->where('direction', 'incoming')
            ->orderBy('id')
            ->select(['id', 'payload'])
            ->chunk(2000, function ($messages) use (&$latest, $only) {
                foreach ($messages as $message) {
                    $payload = $message->payload;

                    if (empty($payload['uuid'])) {
                        continue;
                    }

                    if ($only !== [] && ! in_array($payload['number'] ?? null, $only, true)) {
                        continue;
                    }

                    $latest[$payload['uuid']] = $payload;
                }
            });

        if ($latest === []) {
            return [];
        }

        $existing = Order::withTrashed()
            ->whereIn('uuid', array_keys($latest))
            ->pluck('uuid')
            ->all();

        return array_diff_key($latest, array_flip($existing));
    }
}
