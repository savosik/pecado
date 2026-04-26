<?php

namespace App\Jobs;

use App\Models\Certificate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncCertificateFileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public int $backoff = 15;

    private const STORAGE_BASE = 'https://backend.sex-opt.ru/storage/certificates';

    /**
     * Расширения, проверяемые по очереди — sex-opt хранит файлы как
     * /storage/certificates/{uid}.{ext}, расширение не отдаётся отдельно.
     */
    private const EXTENSIONS = ['pdf', 'jpg', 'png', 'docx', 'zip'];

    public function __construct(
        public int $certificateId,
        public string $fileUid,
    ) {
        $this->onQueue('catalog-media');
    }

    public function handle(): void
    {
        $certificate = Certificate::find($this->certificateId);
        if (! $certificate) {
            Log::warning("SyncCertificateFileJob: сертификат #{$this->certificateId} не найден");

            return;
        }

        $url = $this->resolveUrl();
        if (! $url) {
            Log::warning("SyncCertificateFileJob: файл по uid {$this->fileUid} не найден ни в одном из расширений");

            return;
        }

        $existing = $certificate->getMedia('files')
            ->pluck('custom_properties.source_url')
            ->filter()
            ->all();

        if (in_array($url, $existing, true)) {
            return;
        }

        try {
            $certificate->addMediaFromUrl($url)
                ->withCustomProperties(['source_url' => $url, 'sex_opt_file_uid' => $this->fileUid])
                ->toMediaCollection('files');
        } catch (\Throwable $e) {
            Log::warning("SyncCertificateFileJob: cert={$certificate->id} url={$url}: {$e->getMessage()}");
        }
    }

    private function resolveUrl(): ?string
    {
        foreach (self::EXTENSIONS as $ext) {
            $url = self::STORAGE_BASE."/{$this->fileUid}.{$ext}";
            try {
                $resp = Http::timeout(10)->head($url);
                if ($resp->successful()) {
                    return $url;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
