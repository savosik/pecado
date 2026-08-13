<?php

namespace Tests\Feature\Erp;

use App\Jobs\StorePrintedDocumentFile;
use App\Models\PrintedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Обслуживание хранилища печатных форм (v16.1.0): уборка обменного бакета,
 * освобождение файлов отозванных форм и перезапуск зависших переносов.
 */
class PrintedDocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents-exchange');
        Storage::fake('printed-documents');

        config([
            'documents.exchange_disk' => 'documents-exchange',
            'documents.disk' => 'printed-documents',
        ]);
    }

    #[Test]
    public function clean_exchange_removes_only_stale_files(): void
    {
        $disk = Storage::disk('documents-exchange');

        $disk->put('2026/08/fresh.pdf', '%PDF-1.7 свежий');
        $disk->put('2026/07/stale.pdf', '%PDF-1.7 залежался');

        // Storage::fake хранит файлы локально, поэтому возраст задаётся mtime.
        touch($disk->path('2026/07/stale.pdf'), now()->subDays(30)->timestamp);

        $this->artisan('documents:clean-exchange --days=7')->assertSuccessful();

        $disk->assertExists('2026/08/fresh.pdf');
        $disk->assertMissing('2026/07/stale.pdf');
    }

    #[Test]
    public function clean_exchange_is_skipped_when_retention_disabled(): void
    {
        Storage::disk('documents-exchange')->put('old.pdf', 'x');
        touch(Storage::disk('documents-exchange')->path('old.pdf'), now()->subYear()->timestamp);

        $this->artisan('documents:clean-exchange --days=0')->assertSuccessful();

        Storage::disk('documents-exchange')->assertExists('old.pdf');
    }

    #[Test]
    public function prune_frees_files_of_long_revoked_documents(): void
    {
        Storage::disk('printed-documents')->put('2026/08/revoked.pdf', '%PDF-1.7');
        Storage::disk('printed-documents')->put('2026/08/recent.pdf', '%PDF-1.7');

        $revoked = PrintedDocument::factory()->create(['path' => '2026/08/revoked.pdf']);
        $revoked->delete();
        $revoked->forceFill(['deleted_at' => now()->subDays(120)])->save();

        // Отозвана вчера: 1С ещё может снять пометку удаления, файл нужен.
        $recent = PrintedDocument::factory()->create(['path' => '2026/08/recent.pdf']);
        $recent->delete();

        $alive = PrintedDocument::factory()->create(['path' => '2026/08/alive.pdf']);
        Storage::disk('printed-documents')->put('2026/08/alive.pdf', '%PDF-1.7');

        $this->artisan('documents:prune --days=90')->assertSuccessful();

        Storage::disk('printed-documents')->assertMissing('2026/08/revoked.pdf');
        Storage::disk('printed-documents')->assertExists('2026/08/recent.pdf');
        Storage::disk('printed-documents')->assertExists('2026/08/alive.pdf');

        $this->assertNull(PrintedDocument::withTrashed()->find($revoked->id)->path);
        // Сама строка остаётся: по ней видно, что документ был и когда отозван.
        $this->assertNotNull(PrintedDocument::withTrashed()->find($revoked->id));
        $this->assertNotNull($alive->fresh()->path);
    }

    #[Test]
    public function reconcile_restarts_stuck_transfers(): void
    {
        Bus::fake();

        $stuck = PrintedDocument::factory()->pending()->create([
            'source_url' => 's3://documents-exchange/2026/08/stuck.pdf',
        ]);
        $stuck->forceFill(['updated_at' => now()->subHours(2)])->save();

        // Только что поставлен в очередь — возможно, просто ждёт воркера.
        PrintedDocument::factory()->pending()->create([
            'source_url' => 's3://documents-exchange/2026/08/just-queued.pdf',
        ]);

        $this->artisan('documents:reconcile --minutes=30')->assertSuccessful();

        Bus::assertDispatchedTimes(StorePrintedDocumentFile::class, 1);
        Bus::assertDispatched(
            StorePrintedDocumentFile::class,
            fn (StorePrintedDocumentFile $job) => $job->printedDocumentId === $stuck->id,
        );
    }

    #[Test]
    public function reconcile_ignores_stored_documents(): void
    {
        Bus::fake();

        $stored = PrintedDocument::factory()->create();
        $stored->forceFill(['updated_at' => now()->subDays(5)])->save();

        $this->artisan('documents:reconcile')->assertSuccessful();

        Bus::assertNotDispatched(StorePrintedDocumentFile::class);
    }

    #[Test]
    public function store_job_skips_document_superseded_by_newer_publication(): void
    {
        // Пока задача ждала в очереди, приехала свежая публикация с другим файлом.
        // Дописывать поверх нельзя — клиент получил бы устаревший PDF.
        $document = PrintedDocument::factory()->create([
            'source_url' => 's3://documents-exchange/2026/08/new.pdf',
            'path' => '2026/08/current.pdf',
        ]);
        Storage::disk('printed-documents')->put('2026/08/current.pdf', '%PDF-1.7 актуальный');
        Storage::disk('documents-exchange')->put('2026/08/old.pdf', '%PDF-1.7 устаревший');

        (new StorePrintedDocumentFile($document->id, 's3://documents-exchange/2026/08/old.pdf'))->handle();

        $this->assertSame(
            '%PDF-1.7 актуальный',
            Storage::disk('printed-documents')->get('2026/08/current.pdf'),
        );
        Storage::disk('documents-exchange')->assertExists('2026/08/old.pdf');
    }
}
