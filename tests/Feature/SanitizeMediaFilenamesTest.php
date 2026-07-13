<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\MediaLibrary\SanitizingFileNamer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SanitizeMediaFilenamesTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('baseNameProvider')]
    public function test_sanitize_base_name(string $input, string $expected): void
    {
        $this->assertSame($expected, SanitizingFileNamer::sanitizeBaseName($input));
    }

    public static function baseNameProvider(): array
    {
        return [
            'запятая и кириллица' => ['ChatGPT-Image-2-июл.-2026-г.,-12_36_43', 'ChatGPT-Image-2-iiul-2026-g-12_36_43'],
            'скобки' => ['photo-(2)', 'photo-2'],
            'пробелы' => ['my photo file', 'my-photo-file'],
            'подчёркивания сохраняются' => ['a_b_c', 'a_b_c'],
            'уже безопасное' => ['simple-name_1', 'simple-name_1'],
            'пустой результат' => ['..,,', 'file'],
        ];
    }

    public function test_uploaded_file_with_unsafe_name_is_sanitized(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();

        // Пробелы, запятые и кириллица в имени файла.
        $file = UploadedFile::fake()->image('Фото товара, вид 1.jpg');
        $product->addMedia($file)->toMediaCollection('main');

        $fileName = $product->getFirstMedia('main')->file_name;

        $this->assertStringEndsWith('.jpg', $fileName);
        $this->assertDoesNotMatchRegularExpression('/[^A-Za-z0-9._-]/', $fileName);
    }

    public function test_command_renames_original_and_conversions_on_disk(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();

        $media = $product->addMedia(UploadedFile::fake()->image('temp.jpg'))
            ->toMediaCollection('additional');

        // Эмулируем «старое» небезопасное имя на диске и в БД (как до фикса).
        $disk = Storage::disk('public');
        $badBase = 'Image-2-июл.-2026-г.,-12_36_43';
        $badFile = $badBase.'.jpg';
        $dir = $media->id.'/';

        $disk->move($dir.'temp.jpg', $dir.$badFile);
        $disk->put($dir.'conversions/'.$badBase.'-thumb.jpg', 'x');
        $disk->put($dir.'conversions/'.$badBase.'-large.jpg', 'y');

        $media->file_name = $badFile;
        $media->save();

        $this->artisan('media:sanitize-filenames')->assertSuccessful();

        $media->refresh();
        $newBase = SanitizingFileNamer::sanitizeBaseName($badBase);

        // В БД — безопасное имя.
        $this->assertSame($newBase.'.jpg', $media->file_name);
        $this->assertDoesNotMatchRegularExpression('/[^A-Za-z0-9._-]/', $media->file_name);

        // Оригинал и конверсии переехали на диске.
        $this->assertTrue($disk->exists($dir.$newBase.'.jpg'));
        $this->assertTrue($disk->exists($dir.'conversions/'.$newBase.'-thumb.jpg'));
        $this->assertTrue($disk->exists($dir.'conversions/'.$newBase.'-large.jpg'));

        // Старых файлов больше нет.
        $this->assertFalse($disk->exists($dir.$badFile));
        $this->assertFalse($disk->exists($dir.'conversions/'.$badBase.'-thumb.jpg'));
    }

    public function test_command_skips_already_safe_names(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $product->addMedia(UploadedFile::fake()->image('safe-name.jpg'))
            ->toMediaCollection('additional');

        $this->artisan('media:sanitize-filenames')
            ->expectsOutputToContain('Медиафайлов с небезопасными именами не найдено.')
            ->assertSuccessful();
    }
}
