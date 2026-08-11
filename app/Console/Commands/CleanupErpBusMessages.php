<?php

namespace App\Console\Commands;

use App\Models\ErpBusMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Ретенция лога шины ERP: сообщения старше срока уезжают в холодное хранилище
 * и удаляются из БД.
 *
 * Зачем архив. Раньше команда просто удаляла старые записи, и разобрать инцидент
 * с 1С задним числом было нечем: payload исчезал навсегда. Теперь пачка сначала
 * выгружается в S3 (Yandex Object Storage, ледяной класс) и только потом
 * удаляется — порядок принципиален, обратный давал бы тихую потерю данных.
 *
 * Формат архива: один объект на календарный день, NDJSON + gzip. Строка = одна
 * запись, payload вложен объектом, поэтому архив читается `zcat | jq` без
 * дополнительной распаковки полей:
 *
 *   erp-bus/2026/07/erp_bus_messages-2026-07-12.jsonl.gz
 *   erp-bus/2026/07/erp_bus_messages-2026-07-12.part-02.jsonl.gz   ← повторный прогон
 *
 * Существующий объект никогда не перезаписывается: если прошлый прогон упал
 * между заливкой и удалением, повтор создаст .part-NN, а не сотрёт архив.
 *
 * Если хранилище недоступно — из БД ничего не удаляется, команда возвращает
 * FAILURE. Таблица тем временем растёт, это осознанный размен: потерять лог
 * хуже, чем подождать.
 */
class CleanupErpBusMessages extends Command
{
    protected $signature = 'erp:cleanup-messages
        {--days= : Удалять записи старше N дней (по умолчанию erp.bus_retention_days)}
        {--chunk=1000 : Размер пачки при чтении и удалении}
        {--no-archive : Удалять без выгрузки в холодное хранилище}
        {--dry-run : Показать, что будет сделано, ничего не меняя}';

    protected $description = 'Архивировать в холодное хранилище и удалить старые записи лога шины ERP';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('erp.bus_retention_days', 14));

        if ($days <= 0) {
            $this->info('Ретенция лога шины отключена (erp.bus_retention_days = 0) — ничего не делаем.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));
        $archiveEnabled = ! $this->option('no-archive') && (bool) config('erp.bus_archive.enabled', false);
        $cutoff = now()->subDays($days);

        $total = ErpBusMessage::where('created_at', '<', $cutoff)->count();
        if ($total === 0) {
            $this->info("Записей старше {$days} дней нет.");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%sЗаписей старше %d дней: %d. Архивация: %s.',
            $dryRun ? '[dry-run] ' : '',
            $days,
            $total,
            $archiveEnabled ? 'в диск «'.config('erp.bus_archive.disk').'»' : 'выключена',
        ));

        $archived = 0;
        $deleted = 0;
        $days_processed = 0;

        // Идём по календарным дням от самого старого: так один день = один объект
        // в хранилище, и повторный прогон не размазывает сутки по десятку файлов.
        foreach ($this->daysToProcess($cutoff) as $day) {
            $from = $day->copy()->startOfDay();
            $to = min($day->copy()->endOfDay(), $cutoff);

            $count = ErpBusMessage::whereBetween('created_at', [$from, $to])->count();
            if ($count === 0) {
                continue;
            }

            $days_processed++;

            if ($dryRun) {
                $this->line(sprintf('  [dry-run] %s — %d записей', $from->toDateString(), $count));

                continue;
            }

            if ($archiveEnabled) {
                $key = $this->archiveDay($from, $to, $chunk, $count);
                if ($key === null) {
                    $this->error('Архивация прервана — из БД ничего не удалено.');

                    return self::FAILURE;
                }
                $archived += $count;
                $this->line(sprintf('  [архив]  %s — %d записей → %s', $from->toDateString(), $count, $key));
            }

            $deleted += $this->deleteRange($from, $to, $chunk);
        }

        $summary = sprintf(
            '%sДней обработано: %d, заархивировано: %d, удалено: %d.',
            $dryRun ? '[dry-run] ' : 'Готово. ',
            $days_processed,
            $archived,
            $deleted,
        );
        $this->info($summary);

        if (! $dryRun) {
            Log::info('erp:cleanup-messages', [
                'days' => $days,
                'archived' => $archived,
                'deleted' => $deleted,
                'archive_enabled' => $archiveEnabled,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Календарные дни, в которых есть записи под ретенцию — от самого старого.
     *
     * @return \Generator<Carbon>
     */
    private function daysToProcess(Carbon $cutoff): \Generator
    {
        $oldest = ErpBusMessage::where('created_at', '<', $cutoff)->min('created_at');
        if ($oldest === null) {
            return;
        }

        $day = Carbon::parse($oldest)->startOfDay();
        $last = $cutoff->copy()->startOfDay();

        while ($day->lessThanOrEqualTo($last)) {
            yield $day->copy();
            $day->addDay();
        }
    }

    /**
     * Выгружает сутки в холодное хранилище. Возвращает ключ объекта либо null,
     * если что-то пошло не так — тогда вызывающий код обязан отменить удаление.
     *
     * Пишем потоково через gzopen/gzwrite: складывать десятки тысяч payload-ов
     * в одну строку в памяти нельзя, воркер упрётся в лимит (та же причина, что
     * в ProductExportGenerator::maybeGzipForStatic).
     */
    private function archiveDay(Carbon $from, Carbon $to, int $chunk, int $expected): ?string
    {
        // Диск может быть не настроен вовсе (нет кредов, опечатка в имени) —
        // это тоже «хранилище недоступно», а не повод падать исключением.
        try {
            $disk = Storage::disk(config('erp.bus_archive.disk', 'erp-archive'));
            $key = $this->freeKey($disk, $from);
        } catch (\Throwable $e) {
            Log::error('erp:cleanup-messages: холодное хранилище недоступно', [
                'disk' => config('erp.bus_archive.disk'),
                'error' => $e->getMessage(),
            ]);
            $this->error("Хранилище недоступно: {$e->getMessage()}");

            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'erp-bus-');
        if ($tmp === false) {
            $this->error('Не удалось создать временный файл для архива.');

            return null;
        }

        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            @unlink($tmp);
            $this->error('Не удалось открыть временный файл на запись.');

            return null;
        }

        $written = 0;

        try {
            ErpBusMessage::query()
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('id')
                ->chunkById($chunk, function ($messages) use ($gz, &$written) {
                    foreach ($messages as $message) {
                        gzwrite($gz, json_encode([
                            'id' => $message->id,
                            'direction' => $message->direction,
                            'routing_key' => $message->routing_key,
                            'event' => $message->event,
                            'message_id' => $message->message_id,
                            'status' => $message->status,
                            'error_message' => $message->error_message,
                            'created_at' => $message->created_at?->toIso8601String(),
                            'payload' => $message->payload,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                        $written++;
                    }
                });
        } finally {
            gzclose($gz);
        }

        if ($written !== $expected) {
            @unlink($tmp);
            $this->error("В архив попало {$written} записей вместо {$expected} — прерываем.");

            return null;
        }

        $size = (int) filesize($tmp);

        try {
            $handle = fopen($tmp, 'rb');
            $disk->writeStream($key, $handle);
            if (is_resource($handle)) {
                fclose($handle);
            }

            // Диск заведён с throw => true, но проверяем результат явно: удаление
            // из БД идёт следом, и «кажется, залилось» здесь недопустимо.
            if (! $disk->exists($key) || (int) $disk->size($key) === 0) {
                $this->error("Объект {$key} не найден в хранилище после заливки.");

                return null;
            }
        } catch (\Throwable $e) {
            Log::error('erp:cleanup-messages: не удалось залить архив', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            $this->error("Ошибка заливки {$key}: {$e->getMessage()}");

            return null;
        } finally {
            @unlink($tmp);
        }

        Log::info('erp:cleanup-messages: архив записан', [
            'key' => $key,
            'rows' => $written,
            'bytes' => $size,
        ]);

        return $key;
    }

    /**
     * Свободный ключ для суток: базовый, а при коллизии — .part-02, .part-03…
     * Перезапись существующего объекта запрещена: он мог остаться от прогона,
     * упавшего между заливкой и удалением, и содержать единственную копию.
     */
    private function freeKey(\Illuminate\Contracts\Filesystem\Filesystem $disk, Carbon $day): string
    {
        $prefix = sprintf(
            '%s/%s/%s/erp_bus_messages-%s',
            rtrim((string) config('erp.bus_archive.prefix', 'erp-bus'), '/'),
            $day->format('Y'),
            $day->format('m'),
            $day->toDateString(),
        );

        if (! $disk->exists($prefix.'.jsonl.gz')) {
            return $prefix.'.jsonl.gz';
        }

        for ($part = 2; $part < 100; $part++) {
            $candidate = sprintf('%s.part-%02d.jsonl.gz', $prefix, $part);
            if (! $disk->exists($candidate)) {
                return $candidate;
            }
        }

        // Практически недостижимо, но лучше уникальный ключ, чем перезапись.
        return sprintf('%s.part-%s.jsonl.gz', $prefix, uniqid());
    }

    /**
     * Удаляет диапазон пачками. Один безлимитный DELETE на сотни тысяч строк
     * держал бы длинную транзакцию и раздувал бинлог одной записью.
     */
    private function deleteRange(Carbon $from, Carbon $to, int $chunk): int
    {
        $deleted = 0;

        do {
            $ids = ErpBusMessage::query()
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += ErpBusMessage::whereIn('id', $ids)->delete();
        } while (true);

        return $deleted;
    }
}
