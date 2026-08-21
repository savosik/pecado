<?php

namespace App\Jobs;

use App\Enums\PrintedDocumentFormat;
use App\Models\PrintedDocument;
use App\Services\Crm\Mail\Sources\DocumentOccasions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Перенос файла печатной формы из обменного бакета в хранилище сайта (v16.6.0).
 *
 * 1С кладёт файл в `documents-exchange` и присылает по шине только запись
 * о документе. Задача забирает объект, кладёт его в приватный диск сайта
 * и удаляет исходник. Принимаются PDF, XLSX и XLS — формат определяется
 * по сигнатуре содержимого, а не по тому, что заявлено в сообщении.
 *
 * Почему копируем, а не читаем из обменного бакета при каждом скачивании:
 * обменный бакет обязан чиститься (иначе туда осядут файлы отвалившихся
 * документов навсегда), а печатные формы нужны годами. Один бакет с двумя
 * ретенциями — это уборщик, который однажды снесёт живой счёт-фактуру.
 * Плюс ключи обменного бакета есть у 1С на запись, и она может перезаписать
 * файл под уже показанным клиенту документом.
 */
class StorePrintedDocumentFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(
        public readonly int $printedDocumentId,
        public readonly string $fileUrl,
        public readonly ?string $expectedChecksum = null,
    ) {
        $this->onQueue('documents');
    }

    /**
     * Два переноса одного документа одновременно писали бы в один ключ.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('printed-document:'.$this->printedDocumentId))->expireAfter(600)];
    }

    public function handle(): void
    {
        $document = PrintedDocument::withTrashed()->find($this->printedDocumentId);

        if (! $document) {
            Log::warning('StorePrintedDocumentFile: документ не найден', [
                'printed_document_id' => $this->printedDocumentId,
            ]);

            return;
        }

        // Обгон при перевыставлении: пока задача ждала в очереди, приехала более
        // свежая публикация и уже перенесла свой файл. Дописывать поверх нельзя —
        // клиент получил бы устаревший PDF.
        if ($document->source_url !== $this->fileUrl) {
            Log::info('StorePrintedDocumentFile: документ уже обновлён более свежей публикацией', [
                'printed_document_id' => $document->id,
                'uuid' => $document->uuid,
            ]);

            return;
        }

        $exchange = Storage::disk(config('documents.exchange_disk'));
        $sourcePath = $this->extractFilePath($this->fileUrl);

        if (! $exchange->exists($sourcePath)) {
            // Не бросаем исключение намеренно: иначе каждый неаккуратно выгруженный
            // документ съедал бы пять попыток и уходил в очередь недоставленных.
            // 1С должна прислать сообщение заново, выложив файл.
            $this->markFileState($document, PrintedDocument::FILE_MISSING);

            Log::error('StorePrintedDocumentFile: файла нет в обменном бакете', [
                'printed_document_id' => $document->id,
                'uuid' => $document->uuid,
                'source_path' => $sourcePath,
            ]);

            return;
        }

        $size = (int) $exchange->size($sourcePath);
        $maxSize = (int) config('documents.max_file_size');

        if ($size > $maxSize) {
            $this->reject($document, $exchange, $sourcePath, 'превышен лимит размера', [
                'size_bytes' => $size,
                'max_file_size' => $maxSize,
            ]);

            return;
        }

        $format = $this->detectFormat($exchange, $sourcePath);

        if (! $format) {
            $this->reject($document, $exchange, $sourcePath, 'формат файла не распознан (ожидались PDF, XLSX или XLS)');

            return;
        }

        // Расхождение с mime из сообщения не отклоняет документ: 1С могла забыть
        // поменять строку в коде, а клиенту нужен акт. Верим содержимому, но пишем
        // предупреждение — иначе разъехавшийся контракт останется незамеченным.
        if ($document->mime_type && $document->mime_type !== $format->mime()) {
            Log::warning('StorePrintedDocumentFile: mime_type из 1С не совпал с содержимым файла', [
                'printed_document_id' => $document->id,
                'uuid' => $document->uuid,
                'declared' => $document->mime_type,
                'actual' => $format->mime(),
            ]);
        }

        $document->mime_type = $format->mime();

        $checksum = $this->checksum($exchange, $sourcePath);
        $targetDisk = config('documents.disk');
        $previousPath = $document->path;
        $targetPath = $this->targetPath($document, $format);

        // Тот же файл уже лежит на месте — перевыставили реквизиты, не содержимое.
        // Перекладывать мегабайты незачем: на первичной выгрузке этого набирается много.
        $unchanged = $document->checksum === $checksum
            && $document->disk === $targetDisk
            && $document->path === $targetPath
            && Storage::disk($targetDisk)->exists($targetPath);

        if (! $unchanged) {
            $stream = $exchange->readStream($sourcePath);

            if (! $stream) {
                throw new \RuntimeException("Не удалось открыть поток файла: {$sourcePath}");
            }

            try {
                Storage::disk($targetDisk)->writeStream($targetPath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $document->version = $document->version + 1;
        }

        // Смена формата у той же формы (PDF-акт стал XLSX) меняет расширение, а значит
        // и ключ хранения: старый объект больше ничем не адресуется и остался бы
        // сиротой в приватном бакете навсегда — его никто не найдёт и не удалит.
        if ($previousPath && $previousPath !== $targetPath && $document->disk === $targetDisk) {
            Storage::disk($targetDisk)->delete($previousPath);

            Log::info('StorePrintedDocumentFile: удалён прежний файл формы после смены формата', [
                'printed_document_id' => $document->id,
                'uuid' => $document->uuid,
                'previous_path' => $previousPath,
                'path' => $targetPath,
            ]);
        }

        $document->disk = $targetDisk;
        $document->path = $targetPath;
        $document->size_bytes = $size;
        $document->checksum = $checksum;
        $document->file_status = PrintedDocument::FILE_STORED;
        $document->stored_at = now();
        $document->save();

        // Сигнал пульту уведомлений именно здесь: только теперь файл лежит
        // в хранилище и ссылка в письме откроется. Если контрагент ещё
        // не привязан, диспетчер промолчит — сигнал выставит documents:relink.
        app(DocumentOccasions::class)->published($document);

        // Исходник удаляется всегда: обменный бакет — транспорт, а не хранилище.
        $exchange->delete($sourcePath);

        if ($this->expectedChecksum && ! hash_equals(strtolower($this->expectedChecksum), $checksum)) {
            // Не повод отклонять документ: файл принят и открывается. Но расхождение
            // означает, что 1С посчитала хеш не от того, что выложила, — это ломает
            // дедупликацию при следующих перевыставлениях.
            Log::warning('StorePrintedDocumentFile: контрольная сумма 1С не совпала с фактической', [
                'printed_document_id' => $document->id,
                'uuid' => $document->uuid,
                'expected' => $this->expectedChecksum,
                'actual' => $checksum,
            ]);
        }

        Log::info('StorePrintedDocumentFile: файл перенесён', [
            'printed_document_id' => $document->id,
            'uuid' => $document->uuid,
            'path' => $targetPath,
            'size_bytes' => $size,
            'version' => $document->version,
            'reused' => $unchanged,
        ]);
    }

    /**
     * Ключ в хранилище сайта: `<ГГГГ>/<ММ>/<uuid>.<расширение>`.
     *
     * Детерминирован по uuid, поэтому перевыставление перезаписывает тот же объект
     * и мусор не копится. Год и месяц берутся из даты документа — по ним удобно
     * оценивать объём и разбирать хранилище руками.
     */
    private function targetPath(PrintedDocument $document, PrintedDocumentFormat $format): string
    {
        $prefix = ($document->date ?? $document->created_at ?? now())->format('Y/m');

        return $prefix.'/'.$document->uuid.'.'.$format->extension();
    }

    /**
     * Извлечь относительный путь из s3:// URL.
     *
     * Бакет из URL отбрасывается намеренно: диск фиксирован конфигурацией, и 1С
     * не может заставить сайт читать чужой бакет. Так же сделано в приёме
     * индивидуальных цен (ProcessIndividualPricesFile).
     */
    private function extractFilePath(string $fileUrl): string
    {
        if (str_starts_with($fileUrl, 's3://')) {
            $withoutScheme = substr($fileUrl, 5);
            $slashPos = strpos($withoutScheme, '/');

            return $slashPos !== false ? substr($withoutScheme, $slashPos + 1) : $withoutScheme;
        }

        return ltrim($fileUrl, '/');
    }

    /**
     * Формат по сигнатуре, а не по расширению и не по mime_type из сообщения:
     * и то и другое задаёт 1С, а нам важно, что клиент реально сможет открыть файл.
     */
    private function detectFormat(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): ?PrintedDocumentFormat
    {
        $stream = $disk->readStream($path);

        if (! $stream) {
            return null;
        }

        try {
            $head = fread($stream, PrintedDocumentFormat::SIGNATURE_LENGTH);

            return $head === false ? null : PrintedDocumentFormat::detect($head);
        } finally {
            fclose($stream);
        }
    }

    /**
     * SHA-256 потоком: печатная форма бывает и на десятки мегабайт, целиком
     * в память её тянуть незачем.
     */
    private function checksum(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! $stream) {
            throw new \RuntimeException("Не удалось открыть поток файла: {$path}");
        }

        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    break;
                }

                hash_update($context, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reject(
        PrintedDocument $document,
        \Illuminate\Contracts\Filesystem\Filesystem $exchange,
        string $sourcePath,
        string $reason,
        array $context = [],
    ): void {
        $this->markFileState($document, PrintedDocument::FILE_REJECTED);

        // Исходник удаляем и здесь: иначе отклонённые файлы копились бы
        // в обменном бакете до самой ретенции.
        $exchange->delete($sourcePath);

        Log::error('StorePrintedDocumentFile: файл отклонён — '.$reason, array_merge([
            'printed_document_id' => $document->id,
            'uuid' => $document->uuid,
            'source_path' => $sourcePath,
        ], $context));
    }

    private function markFileState(PrintedDocument $document, string $status): void
    {
        $document->file_status = $status;
        $document->save();
    }
}
