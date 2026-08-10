<?php

namespace App\Console\Commands;

use App\Models\Delivery\DeliveryShipment;
use App\Services\Delivery\ApiShip\ApiShipClient;
use App\Services\Delivery\DeliveryStatusSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сверка статусов отправок с ApiShip.
 *
 * Основной канал — вебхук ORDER_STATUS, эта команда его подстраховывает: если наш
 * сервер лежал дольше трёх попыток доставки вебхука (таймаут 30 с), событие теряется
 * навсегда и восстановить его больше нечем.
 *
 * Один запрос GET /orders/statuses/interval отдаёт изменения по всем заявкам сразу —
 * поштучный опрос каждой отправки был бы на два порядка дороже.
 */
class ApiShipSyncStatuses extends Command
{
    protected $signature = 'apiship:sync-statuses
                            {--since= : Начало интервала (Y-m-d H:i:s). По умолчанию — с прошлой успешной сверки}
                            {--dry-run : Показать, что изменилось бы, ничего не сохраняя}';

    protected $description = 'Сверить статусы отправок с ApiShip (страховка на случай потерянного вебхука)';

    /** Ключ кэша с временем последней успешной сверки. */
    private const CURSOR_KEY = 'apiship:statuses:last_sync';

    /**
     * Нахлёст назад. Событие могло произойти в момент прошлой сверки и не попасть
     * в её интервал — пять минут перекрытия дешевле потерянного статуса.
     */
    private const OVERLAP_MINUTES = 5;

    public function handle(ApiShipClient $client, DeliveryStatusSynchronizer $synchronizer): int
    {
        if (! $client->enabled()) {
            $this->info('Интеграция с ApiShip выключена — сверка пропущена.');

            return self::SUCCESS;
        }

        $to = Carbon::now();
        $from = $this->resolveFrom($to);

        $this->info("Сверяем статусы за период {$from->format('d.m.Y H:i')} — {$to->format('d.m.Y H:i')}");

        $result = $client->getStatusesInterval($from, $to);

        if (! $result->ok) {
            $this->error('ApiShip не ответил: '.$result->error);

            return self::FAILURE;
        }

        $rows = $result->data()['rows'] ?? $result->data()['statuses'] ?? [];

        if (! is_array($rows) || $rows === []) {
            $this->info('Изменений статусов нет.');
            $this->rememberCursor($to);

            return self::SUCCESS;
        }

        $applied = 0;
        $skipped = 0;
        $unknown = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $orderInfo = is_array($row['orderInfo'] ?? null) ? $row['orderInfo'] : $row;
            $status = is_array($row['status'] ?? null) ? $row['status'] : $row;

            $delivery = $synchronizer->resolve($orderInfo);

            if ($delivery === null) {
                $unknown++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  {$delivery->number}: {$delivery->apiship_status_key} → ".($status['key'] ?? '?'));
                $applied++;

                continue;
            }

            if ($synchronizer->apply($delivery, $orderInfo, $status, $synchronizer->sourcePoll())) {
                $applied++;
            } else {
                $skipped++;
            }
        }

        $this->info("Обновлено: {$applied}, без изменений: {$skipped}, чужих заявок: {$unknown}.");

        if ($applied > 0 && ! $this->option('dry-run')) {
            // Сверка нашла то, что должен был принести вебхук, — повод посмотреть,
            // жива ли подписка (apiship:register-webhook --list).
            Log::info('ApiShip: сверка догнала статусы, пропущенные вебхуком', ['count' => $applied]);
        }

        if (! $this->option('dry-run')) {
            $this->rememberCursor($to);
        }

        $this->reportStuck();

        return self::SUCCESS;
    }

    /**
     * Начало интервала: явный --since, курсор прошлой сверки или сутки назад.
     */
    private function resolveFrom(Carbon $to): Carbon
    {
        $since = $this->option('since');

        if (is_string($since) && $since !== '') {
            return Carbon::parse($since);
        }

        $cursor = Cache::get(self::CURSOR_KEY);

        if (is_string($cursor) && $cursor !== '') {
            return Carbon::parse($cursor)->subMinutes(self::OVERLAP_MINUTES);
        }

        // Первый запуск: сутки назад. Больше брать бессмысленно — все свежие
        // статусы уже пришли вебхуками.
        return $to->copy()->subDay();
    }

    private function rememberCursor(Carbon $to): void
    {
        // Курсор переживает перезапуск воркеров, но не вечен: неделя простоя
        // означает, что сверять по нему уже нечего.
        Cache::put(self::CURSOR_KEY, $to->toDateTimeString(), now()->addWeek());
    }

    /**
     * Отправки, по которым перевозчик молчит дольше трёх суток.
     */
    private function reportStuck(): void
    {
        $stuck = DeliveryShipment::query()
            ->trackable()
            ->where('submitted_at', '<', now()->subDays(3))
            ->where(fn ($query) => $query
                ->whereNull('apiship_status_at')
                ->orWhere('apiship_status_at', '<', now()->subDays(3)))
            ->count();

        if ($stuck > 0) {
            $this->warn("Отправок без движения больше трёх суток: {$stuck}.");
        }
    }
}
