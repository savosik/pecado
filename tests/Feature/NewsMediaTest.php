<?php

namespace Tests\Feature;

use App\Models\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewsMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_add_list_item_image_to_news()
    {
        Storage::fake('public');
        $news = News::factory()->create();
        $file = UploadedFile::fake()->image('list-item.jpg');

        $news->addMedia($file)->toMediaCollection('list-item');

        $this->assertCount(1, $news->getMedia('list-item'));
        $this->assertEquals('list-item.jpg', $news->getFirstMedia('list-item')->file_name);
    }

    public function test_can_add_detail_item_desktop_image_to_news()
    {
        Storage::fake('public');
        $news = News::factory()->create();
        $file = UploadedFile::fake()->image('desktop.jpg');

        $news->addMedia($file)->toMediaCollection('detail-item-desktop');

        $this->assertCount(1, $news->getMedia('detail-item-desktop'));
        $this->assertEquals('desktop.jpg', $news->getFirstMedia('detail-item-desktop')->file_name);
    }

    public function test_can_add_detail_item_mobile_image_to_news()
    {
        Storage::fake('public');
        $news = News::factory()->create();
        $file = UploadedFile::fake()->image('mobile.jpg');

        $news->addMedia($file)->toMediaCollection('detail-item-mobile');

        $this->assertCount(1, $news->getMedia('detail-item-mobile'));
        $this->assertEquals('mobile.jpg', $news->getFirstMedia('detail-item-mobile')->file_name);
    }

    public function test_list_item_collection_accepts_only_single_file()
    {
        Storage::fake('public');
        $news = News::factory()->create();
        $file1 = UploadedFile::fake()->image('image1.jpg');
        $file2 = UploadedFile::fake()->image('image2.jpg');

        $news->addMedia($file1)->toMediaCollection('list-item');
        $news->addMedia($file2)->toMediaCollection('list-item');

        $this->assertCount(1, $news->fresh()->getMedia('list-item'));
        $this->assertEquals('image2.jpg', $news->getFirstMedia('list-item')->file_name);
    }

    public function test_detail_item_desktop_collection_accepts_only_single_file()
    {
        Storage::fake('public');
        $news = News::factory()->create();
        $file1 = UploadedFile::fake()->image('desktop1.jpg');
        $file2 = UploadedFile::fake()->image('desktop2.jpg');

        $news->addMedia($file1)->toMediaCollection('detail-item-desktop');
        $news->addMedia($file2)->toMediaCollection('detail-item-desktop');

        $this->assertCount(1, $news->fresh()->getMedia('detail-item-desktop'));
        $this->assertEquals('desktop2.jpg', $news->getFirstMedia('detail-item-desktop')->file_name);
    }
}
