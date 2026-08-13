<?php

namespace App\Console\Commands;

use App\Models\PrintedDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Физическое удаление файлов отозванных печатных форм (v16.1.0).
 *
 * `printed_document.deleted` помечает форму удалённой, но файл оставляет: снятие
 * пометки удаления в 1С — обычная операция, а перезалить PDF заново неоткуда.
 * Через ретенцию файл всё же надо освободить — иначе хранилище копит документы,
 * которые никто уже не откроет.
 *
 * Удаляется только файл. Сама строка остаётся: по ней видно, что документ был
 * и когда его отозвали, а весит она несопоставимо меньше PDF.
 */
class PrunePrintedDocuments extends Command
{
    protected $signature = 'documents:prune {--days= : Удалять файлы форм, отозванных более N дней назад} {--chunk=200 : Размер батча}';

    protected $description = 'Удаление файлов печатных форм, отозванных 1С более N дней назад';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('documents.trashed_retention_days');

        if ($days <= 0) {
            $this->info('Ретенция отозванных форм отключена — пропуск.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $chunk = max(1, (int) $this->option('chunk'));
        $deleted = 0;

        $this->info("Удаление файлов форм, отозванных до {$cutoff->toDateTimeString()}...");

        PrintedDocument::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->whereNotNull('path')
            ->chunkById($chunk, function ($documents) use (&$deleted) {
                foreach ($documents as $document) {
                    try {
                        Storage::disk($document->disk)->delete($document->path);
                    } catch (\Throwable $e) {
                        // Файла может уже не быть — прошлый прогон упал после
                        // удаления, но до сохранения строки. Это не ошибка.
                        Log::warning('documents:prune: не удалось удалить файл', [
                            'printed_document_id' => $document->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $document->forceFill([
                        'disk' => null,
                        'path' => null,
                        'checksum' => null,
                        'stored_at' => null,
                        'file_status' => PrintedDocument::FILE_MISSING,
                    ])->save();

                    $deleted++;
                }
            });

        $this->info("Готово. Удалено файлов: {$deleted}");

        Log::info('documents:prune: очистка завершена', [
            'deleted' => $deleted,
            'days_threshold' => $days,
        ]);

        return self::SUCCESS;
    }
}
