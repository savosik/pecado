<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Support\CertificateFilename;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateController extends Controller
{
    /**
     * Скачать файл сертификата с корректным именем (название + расширение).
     *
     * Файл отдаётся через контроллер с заголовком Content-Disposition,
     * чтобы имя было человекочитаемым и всегда содержало расширение —
     * прямой доступ к media отдавал файл с хеш-именем либо вовсе без формата.
     */
    public function download(Certificate $certificate, Media $media): StreamedResponse
    {
        // media должно принадлежать этому сертификату и коллекции файлов
        abort_unless(
            (int) $media->model_id === (int) $certificate->id
                && $media->model_type === Certificate::class
                && $media->collection_name === 'files',
            404
        );

        $disk = $media->disk;
        // относительный путь работает и на локальном диске, и на s3
        $path = $media->getPathRelativeToRoot();

        abort_unless(Storage::disk($disk)->exists($path), 404, 'Файл не найден');

        $downloadName = CertificateFilename::for($certificate->name, $media);

        return Storage::disk($disk)->download($path, $downloadName);
    }
}
