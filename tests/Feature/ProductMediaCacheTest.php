<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Product;
use App\Models\Scopes\HiddenScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductMediaCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_files_survive_product_deletion(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['code' => 'TEST-DEL-001']);

        // Добавляем медиа вручную с product_code в custom_properties
        $file = UploadedFile::fake()->image('main.jpg');
        $product->addMedia($file)
            ->withCustomProperties(['product_code' => 'TEST-DEL-001'])
            ->toMediaCollection('main');

        $this->assertCount(1, $product->getMedia('main'));

        // Запоминаем путь файла в Storage
        $media = $product->getFirstMedia('main');
        $filePath = $media->getPath();

        // Удаляем товар — должны вызваться deletePreservingMedia
        $product->delete();

        // Записи media в БД должны быть удалены
        $this->assertDatabaseMissing('media', ['id' => $media->id]);

        // Файл в Storage должен остаться
        $files = Storage::disk('public')->allFiles();
        
        $this->assertNotEmpty(
            array_filter($files, fn($f) => str_contains($f, 'TEST-DEL-001')),
            'Файлы должны остаться в Storage после удаления товара: ' . implode(', ', $files)
        );
    }

    public function test_restore_command_creates_media_records_without_downloading(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['code' => 'RESTORE-001', 'hidden' => false]);

        // Эмулируем файл уже лежащий в MinIO по стабильному пути
        $disk = config('media-library.disk_name');
        Storage::disk($disk)->put('products-media/RESTORE-001/main/photo.jpg', 'fake-image-data');

        // Убеждаемся что у товара нет медиа
        $this->assertCount(0, $product->getMedia('main'));

        $this->artisan('catalog:restore-cached-media')
            ->assertExitCode(0);

        // После команды должна появиться запись в media таблице
        $product->refresh();
        $this->assertCount(1, $product->getMedia('main'));

        $media = $product->getFirstMedia('main');
        $this->assertEquals('RESTORE-001', $media->getCustomProperty('product_code'));
    }

    public function test_restore_command_dry_run_does_not_create_records(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['code' => 'DRY-RESTORE-001', 'hidden' => false]);

        $disk = config('media-library.disk_name');
        Storage::disk($disk)->put('products-media/DRY-RESTORE-001/main/photo.jpg', 'fake-image-data');

        $this->artisan('catalog:restore-cached-media --dry-run')
            ->assertExitCode(0);

        $product->refresh();
        $this->assertCount(0, $product->getMedia('main'));
    }

    public function test_purge_command_deletes_orphan_directories_from_minio(): void
    {
        Storage::fake('public');

        $disk = config('media-library.disk_name');

        // Директория без товара — должна быть удалена
        Storage::disk($disk)->put('products-media/DEAD-999/main/photo.jpg', 'data');

        // Директория с существующим товаром — должна остаться
        Product::factory()->create(['code' => 'LIVE-001']);
        Storage::disk($disk)->put('products-media/LIVE-001/main/photo.jpg', 'data');

        $this->artisan('catalog:purge-media-cache')
            ->assertExitCode(0);

        $this->assertFalse(Storage::disk($disk)->directoryExists('products-media/DEAD-999'));
        $this->assertTrue(Storage::disk($disk)->directoryExists('products-media/LIVE-001'));
    }

    public function test_purge_command_dry_run_does_not_delete(): void
    {
        Storage::fake('public');

        $disk = config('media-library.disk_name');
        Storage::disk($disk)->put('products-media/ORPHAN-DRY/main/photo.jpg', 'data');

        $this->artisan('catalog:purge-media-cache --dry-run')
            ->assertExitCode(0);

        $this->assertTrue(Storage::disk($disk)->directoryExists('products-media/ORPHAN-DRY'));
    }
}
