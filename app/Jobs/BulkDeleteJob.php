<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Фоновое удаление всех записей ресурса.
 *
 * Для больших таблиц (>10k записей) удаление выполняется
 * чанками, чтобы не блокировать БД и PHP-FPM.
 *
 * Прогресс кешируется и доступен фронтенду через
 * GET /admin/bulk-delete-status/{resource}.
 */
class BulkDeleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Максимальное время выполнения (минуты).
     */
    public int $timeout = 1800; // 30 минут

    public int $tries = 1;

    public function __construct(
        public string $resourceSlug,
        public string $modelClass,
        public string $method,
        public string $label,
    ) {}

    /**
     * Ключ кеша для прогресса удаления.
     */
    public static function cacheKey(string $resource): string
    {
        return "bulk_delete_progress:{$resource}";
    }

    public function handle(): void
    {
        $cacheKey = self::cacheKey($this->resourceSlug);

        try {
            $this->updateProgress($cacheKey, 'running', 'Подготовка к удалению...');

            if ($this->method === 'truncate') {
                $this->handleTruncate($cacheKey);
            } else {
                $this->handleEach($cacheKey);
            }

            // Очищаем связанный кеш статистики
            Cache::forget('individual_prices_stats');

            $this->updateProgress($cacheKey, 'completed', "Все записи раздела «{$this->label}» успешно удалены.");

            Log::info("BulkDeleteJob completed", [
                'resource' => $this->resourceSlug,
                'model' => $this->modelClass,
            ]);
        } catch (\Throwable $e) {
            $this->updateProgress($cacheKey, 'failed', "Ошибка при удалении: {$e->getMessage()}");

            Log::error("BulkDeleteJob failed", [
                'resource' => $this->resourceSlug,
                'model' => $this->modelClass,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Быстрое удаление через TRUNCATE TABLE.
     * Мгновенная операция, работает на любом количестве записей.
     */
    private function handleTruncate(string $cacheKey): void
    {
        $model = new $this->modelClass;
        $table = $model->getTable();

        $this->updateProgress($cacheKey, 'running', 'Выполняется TRUNCATE TABLE...');

        // TRUNCATE TABLE — мгновенно, не генерирует undo-логи
        Schema::disableForeignKeyConstraints();
        DB::table($table)->truncate();
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Удаление через цикл по чанкам — срабатывают события модели.
     */
    private function handleEach(string $cacheKey): void
    {
        $modelClass = $this->modelClass;
        $total = $modelClass::count();
        $deleted = 0;
        $chunkSize = 500;

        $this->updateProgress($cacheKey, 'running', "Удаление: 0 / {$total}", 0, $total);

        // Удаляем чанками без транзакции — каждый чанк атомарен
        while (true) {
            $records = $modelClass::query()->limit($chunkSize)->get();

            if ($records->isEmpty()) {
                break;
            }

            foreach ($records as $record) {
                $record->delete();
                $deleted++;
            }

            $this->updateProgress(
                $cacheKey,
                'running',
                "Удаление: {$deleted} / {$total}",
                $deleted,
                $total
            );
        }
    }

    /**
     * Обновить прогресс в кеше.
     */
    private function updateProgress(
        string $cacheKey,
        string $status,
        string $message,
        int $deleted = 0,
        int $total = 0
    ): void {
        Cache::put($cacheKey, [
            'status' => $status,
            'message' => $message,
            'deleted' => $deleted,
            'total' => $total,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(1));
    }
}
