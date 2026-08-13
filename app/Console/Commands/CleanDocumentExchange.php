<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Уборка обменного бакета печатных форм (v16.1.0).
 *
 * Штатно файл удаляет сам StorePrintedDocumentFile сразу после переноса. Эта
 * команда — страховка от «зомби»: сообщение о документе потерялось, а объект
 * в бакете остался, и без уборки он лежал бы там вечно.
 *
 * Отдельная команда, а не расширение app:clean-price-dumps: у бакета цен горизонт
 * трое суток, и печатная форма, не успевшая перенестись, была бы им снесена.
 */
class CleanDocumentExchange extends Command
{
    protected $signature = 'documents:clean-exchange {--days= : Удалять файлы старше N дней (по умолчанию documents.exchange_retention_days)}';

    protected $description = 'Удаление невостребованных файлов из обменного бакета печатных форм';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('documents.exchange_retention_days');

        if ($days <= 0) {
            $this->info('Ретенция обменного бакета отключена — пропуск.');

            return self::SUCCESS;
        }

        $disk = Storage::disk(config('documents.exchange_disk'));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        $this->info("Удаление файлов старше {$days} дней (до {$cutoff->toDateTimeString()})...");

        try {
            foreach ($disk->allFiles() as $file) {
                if ($disk->lastModified($file) < $cutoff->timestamp) {
                    $disk->delete($file);
                    $deleted++;
                    $this->line("  Удалён: {$file}");
                }
            }
        } catch (\Throwable $e) {
            $this->error("Ошибка: {$e->getMessage()}");

            Log::error('documents:clean-exchange: ошибка', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info("Готово. Удалено файлов: {$deleted}");

        Log::info('documents:clean-exchange: очистка завершена', [
            'deleted' => $deleted,
            'days_threshold' => $days,
        ]);

        return self::SUCCESS;
    }
}
