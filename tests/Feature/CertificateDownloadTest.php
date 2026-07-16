<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Support\CertificateFilename;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class CertificateDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function makeCertificateWithFile(string $fileName = 'a1b2c3d4e5f6.pdf'): Certificate
    {
        Storage::fake('public');

        $certificate = Certificate::create([
            'name' => 'Свидетельство о гос. рег. №RU.77.99.88.003',
            'type' => 'Свидетельство о гос. рег.',
        ]);

        // реальное содержимое PDF, иначе fake-файл пустой и не проходит mime-проверку коллекции
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
        $certificate->addMedia(UploadedFile::fake()->createWithContent($fileName, $pdfContent))
            ->toMediaCollection('files');

        return $certificate;
    }

    public function test_download_uses_certificate_name_with_extension(): void
    {
        $certificate = $this->makeCertificateWithFile();
        $media = $certificate->getFirstMedia('files');

        $response = $this->get(route('certificates.download', [
            'certificate' => $certificate->id,
            'media' => $media->id,
        ]));

        $response->assertOk();

        // имя в Content-Disposition (filename*=utf-8'') — название сертификата + расширение
        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertSame(
            'Свидетельство о гос. рег. №RU.77.99.88.003.pdf',
            $this->dispositionFilename($disposition)
        );
    }

    /**
     * Извлечь и декодировать имя файла из заголовка Content-Disposition.
     */
    private function dispositionFilename(string $disposition): string
    {
        if (preg_match("/filename\*=utf-8''([^;]+)/i", $disposition, $m)) {
            return rawurldecode($m[1]);
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
            return $m[1];
        }

        return '';
    }

    public function test_download_derives_extension_from_mime_when_filename_has_none(): void
    {
        $certificate = $this->makeCertificateWithFile();
        $media = $certificate->getFirstMedia('files');

        // эмулируем media без расширения в имени (как бывает при импорте по URL без формата)
        $media->file_name = 'a1b2c3d4e5f6';
        $media->save();

        $name = CertificateFilename::for($certificate->name, $media->fresh());

        $this->assertSame('Свидетельство о гос. рег. №RU.77.99.88.003.pdf', $name);
    }

    public function test_download_rejects_media_from_another_certificate(): void
    {
        $certificate = $this->makeCertificateWithFile();
        $other = $this->makeCertificateWithFile();
        $otherMedia = $other->getFirstMedia('files');

        $this->get(route('certificates.download', [
            'certificate' => $certificate->id,
            'media' => $otherMedia->id,
        ]))->assertNotFound();
    }

    public function test_filename_sanitizes_unsafe_characters(): void
    {
        $media = new Media;
        $media->file_name = 'hash.pdf';
        $media->mime_type = 'application/pdf';

        $name = CertificateFilename::for('Тест/файл:имя*«кавычки»', $media);

        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString(':', $name);
        $this->assertStringNotContainsString('*', $name);
        $this->assertStringEndsWith('.pdf', $name);
    }
}
