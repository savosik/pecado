<?php

namespace App\Console\Commands;

use App\Models\ErpProcessedMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RabbitMQStatus extends Command
{
    protected $signature = 'rabbitmq:status';

    protected $description = 'Диагностика потока сообщений RabbitMQ / ERP: очереди, обработанные, ошибки';

    /**
     * Очереди, которые мы отслеживаем.
     */
    private const QUEUE_GROUPS = [
        'Входящие (1С → Сайт)' => [
            'erp_in.partners',
            'erp_in.prices',
            'erp_in.stock',
            'erp_in.orders',
            'erp_in.returns',
            'erp_in.documents',
            'erp_in.balance',
            'erp_in.segments',
            'erp_in.catalog',
        ],
        'DLQ (Dead Letter)' => [
            'erp_dlq.partners',
            'erp_dlq.prices',
            'erp_dlq.stock',
            'erp_dlq.orders',
            'erp_dlq.returns',
            'erp_dlq.documents',
            'erp_dlq.balance',
            'erp_dlq.segments',
            'erp_dlq.catalog',
        ],
        'Исходящие (Сайт → 1С)' => [
            'erp_out.orders',
            'erp_out.returns',
            'erp_out.partners',
        ],
    ];

    public function handle(): int
    {
        $this->info('');
        $this->info('═══════════════════════════════════════════════════');
        $this->info('   📡 Диагностика RabbitMQ / ERP');
        $this->info('   '.now()->format('d.m.Y H:i:s'));
        $this->info('═══════════════════════════════════════════════════');

        // 1. Статус очередей
        $this->displayQueueStatus();

        // 2. Последние обработанные сообщения
        $this->displayRecentProcessed();

        // 3. Последние ошибки (failed_jobs)
        $this->displayRecentFailed();

        // 4. Вывод вердикта
        $this->displayDiagnostic();

        return Command::SUCCESS;
    }

    /**
     * Получить данные об очередях через RabbitMQ Management HTTP API.
     */
    private function fetchQueues(): array
    {
        $host = config('queue.connections.rabbitmq.hosts.0.host', 'rabbitmq');
        $user = config('queue.connections.rabbitmq.management.user', 'guest');
        $password = config('queue.connections.rabbitmq.management.password', 'guest');
        $port = config('queue.connections.rabbitmq.management.port', 15672);

        try {
            $response = Http::withBasicAuth($user, $password)
                ->timeout(5)
                ->get("http://{$host}:{$port}/api/queues");

            if ($response->successful()) {
                return $response->json();
            }

            $this->error("RabbitMQ Management API вернул статус: {$response->status()}");

            return [];
        } catch (\Exception $e) {
            $this->error("Не удалось подключиться к RabbitMQ Management API: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Отобразить статус очередей.
     */
    private function displayQueueStatus(): void
    {
        $this->newLine();
        $this->info('📬 СТАТУС ОЧЕРЕДЕЙ');
        $this->info('─────────────────────────────────────────────────');

        $queues = $this->fetchQueues();

        if (empty($queues)) {
            $this->warn('  Не удалось получить данные из RabbitMQ.');

            return;
        }

        // Индексируем по имени
        $queueMap = [];
        foreach ($queues as $q) {
            $queueMap[$q['name']] = $q;
        }

        foreach (self::QUEUE_GROUPS as $groupName => $queueNames) {
            $this->newLine();
            $this->line("  <fg=cyan;options=bold>{$groupName}</>");

            $rows = [];
            foreach ($queueNames as $name) {
                $q = $queueMap[$name] ?? null;
                $ready = $q['messages_ready'] ?? 0;
                $unacked = $q['messages_unacknowledged'] ?? 0;
                $total = $ready + $unacked;

                if (! $q) {
                    $status = '⚠️  Не найдена';
                } elseif (str_starts_with($name, 'erp_dlq.') && $total > 0) {
                    $status = "🔴 {$total} мёртвых";
                } elseif ($total > 0) {
                    $status = "🟡 {$ready} ожидают / {$unacked} в обработке";
                } else {
                    $status = '🟢 Пустая';
                }

                $rows[] = [$name, $ready, $unacked, $status];
            }

            $this->table(['Очередь', 'Ready', 'Unacked', 'Статус'], $rows);
        }
    }

    /**
     * Отобразить последние обработанные сообщения.
     */
    private function displayRecentProcessed(): void
    {
        $this->newLine();
        $this->info('✅ ПОСЛЕДНИЕ ОБРАБОТАННЫЕ СООБЩЕНИЯ (топ-10)');
        $this->info('─────────────────────────────────────────────────');

        $messages = ErpProcessedMessage::orderByDesc('processed_at')
            ->take(10)
            ->get();

        if ($messages->isEmpty()) {
            $this->warn('  Нет обработанных сообщений в таблице erp_processed_messages.');
            $this->warn('  → Это может означать, что 1С не посылала сообщений!');

            return;
        }

        $rows = $messages->map(fn ($m) => [
            $m->message_id,
            $m->event,
            $m->processed_at?->format('d.m.Y H:i:s') ?? '—',
        ])->toArray();

        $this->table(['Message ID', 'Событие', 'Обработано'], $rows);

        // Статистика по типам событий
        $this->newLine();
        $this->info('📊 Статистика по типам событий (за всё время):');

        $stats = ErpProcessedMessage::selectRaw('event, COUNT(*) as count, MAX(processed_at) as last_at')
            ->groupBy('event')
            ->orderByDesc('count')
            ->get();

        $statRows = $stats->map(fn ($s) => [
            $s->event,
            $s->count,
            $s->last_at ? \Carbon\Carbon::parse($s->last_at)->format('d.m.Y H:i:s') : '—',
        ])->toArray();

        $this->table(['Событие', 'Кол-во', 'Последнее'], $statRows);
    }

    /**
     * Отобразить последние ошибки из failed_jobs.
     */
    private function displayRecentFailed(): void
    {
        $this->newLine();
        $this->info('❌ ПОСЛЕДНИЕ ОШИБКИ (failed_jobs, топ-5)');
        $this->info('─────────────────────────────────────────────────');

        $failed = DB::table('failed_jobs')
            ->where('connection', 'like', '%rabbitmq%')
            ->orderByDesc('failed_at')
            ->take(5)
            ->get();

        if ($failed->isEmpty()) {
            $this->line('  <fg=green>Ошибок нет! 🎉</>');

            return;
        }

        foreach ($failed as $job) {
            $exception = \Illuminate\Support\Str::limit($job->exception, 200);
            $this->newLine();
            $this->error("  [{$job->failed_at}] Queue: {$job->queue}");
            $this->line("  <fg=red>{$exception}</>");
        }
    }

    /**
     * Вывести диагностический вердикт.
     */
    private function displayDiagnostic(): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════');
        $this->info('   🔍 ДИАГНОСТИКА');
        $this->info('═══════════════════════════════════════════════════');

        $processedCount = ErpProcessedMessage::count();
        $failedCount = DB::table('failed_jobs')
            ->where('connection', 'like', '%rabbitmq%')
            ->count();

        $queues = $this->fetchQueues();
        $dlqTotal = 0;
        $inQueueTotal = 0;

        foreach ($queues as $q) {
            $name = $q['name'] ?? '';
            $msgs = ($q['messages_ready'] ?? 0) + ($q['messages_unacknowledged'] ?? 0);

            if (str_starts_with($name, 'erp_dlq.')) {
                $dlqTotal += $msgs;
            } elseif (str_starts_with($name, 'erp_in.')) {
                $inQueueTotal += $msgs;
            }
        }

        $this->newLine();

        if ($processedCount === 0 && $failedCount === 0 && $dlqTotal === 0 && $inQueueTotal === 0) {
            $this->warn('  ⚪ Нет данных: очереди пустые, нет обработанных, нет ошибок.');
            $this->warn('  → Вероятно, 1С НЕ ПОСЫЛАЛА сообщений в шину.');
            $this->warn('  → Проверьте настройки обмена на стороне 1С.');
        } else {
            if ($processedCount > 0) {
                $this->line("  🟢 Обработано сообщений: <fg=green;options=bold>{$processedCount}</>");
            }

            if ($inQueueTotal > 0) {
                $this->line("  🟡 В очередях ожидают: <fg=yellow;options=bold>{$inQueueTotal}</>");
            }

            if ($dlqTotal > 0) {
                $this->line("  🔴 В DLQ (упавшие): <fg=red;options=bold>{$dlqTotal}</>");
                $this->warn('  → Проверьте DLQ через RabbitMQ Management UI или логи воркеров.');
            }

            if ($failedCount > 0) {
                $this->line("  🔴 Failed Jobs: <fg=red;options=bold>{$failedCount}</>");
                $this->warn('  → Запустите php artisan queue:retry all для повторной обработки.');
            }

            if ($dlqTotal === 0 && $failedCount === 0 && $inQueueTotal === 0 && $processedCount > 0) {
                $this->line('  ✅ Всё работает штатно: сообщения обработаны, ошибок нет.');
            }
        }

        $this->newLine();
    }
}
