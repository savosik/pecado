<?php

namespace App\Console\Commands;

use App\Jobs\StorePrintedDocumentFile;
use App\Models\PrintedDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Перезапуск зависшего переноса файлов печатных форм (v16.1.0).
 *
 * Документ остаётся в состоянии «ожидает переноса», если задача не дошла до
 * воркера: очередь чистили, воркер падал, Redis перезапускался. Сам по себе он
 * из этого состояния не выйдет, а клиенту такой документ не показывается —
 * то есть тихо теряется.
 *
 * Порог в 30 минут по умолчанию: на первичной выгрузке очередь бывает длинной,
 * и перезапускать задачу, которая просто ждёт своей очереди, незачем.
 */
class ReconcilePrintedDocuments extends Command
{
    protected $signature = 'documents:reconcile
        {--minutes=30 : Перезапускать задачи для документов, ожидающих переноса дольше N минут}
        {--limit=500 : Максимум документов за прогон}';

    protected $description = 'Перезапуск переноса файлов для печатных форм, зависших в состоянии «ожидает переноса»';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));

        $documents = PrintedDocument::query()
            ->where('file_status', PrintedDocument::FILE_PENDING)
            ->whereNotNull('source_url')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'uuid', 'source_url']);

        if ($documents->isEmpty()) {
            $this->info('Зависших переносов нет.');

            return self::SUCCESS;
        }

        foreach ($documents as $document) {
            StorePrintedDocumentFile::dispatch($document->id, $document->source_url);
            $this->line("  Перезапущен перенос: {$document->uuid}");
        }

        $this->info("Готово. Перезапущено: {$documents->count()}");

        Log::info('documents:reconcile: перезапуск переносов', [
            'count' => $documents->count(),
            'minutes_threshold' => $minutes,
        ]);

        return self::SUCCESS;
    }
}
