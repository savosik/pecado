<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanPriceDumps extends Command
{
    protected $signature = 'app:clean-price-dumps {--days=3 : Удалять файлы старше N дней}';

    protected $description = 'Удаление старых дампов индивидуальных цен из MinIO (бакет prices-exchange)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $disk = Storage::disk('prices-exchange');
        $cutoff = now()->subDays($days);
        $deleted = 0;

        $this->info("Удаление файлов старше {$days} дней (до {$cutoff->toDateTimeString()})...");

        try {
            $files = $disk->allFiles();

            foreach ($files as $file) {
                $lastModified = $disk->lastModified($file);

                if ($lastModified < $cutoff->timestamp) {
                    $disk->delete($file);
                    $deleted++;
                    $this->line("  Удалён: {$file}");
                }
            }

            $this->info("Готово. Удалено файлов: {$deleted}");

            Log::info('app:clean-price-dumps: очистка завершена', [
                'deleted' => $deleted,
                'days_threshold' => $days,
            ]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка: {$e->getMessage()}");

            Log::error('app:clean-price-dumps: ошибка', [
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }
}
