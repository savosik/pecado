<?php

namespace Tests\Feature;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PageMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_add_list_item_image_to_page()
    {
        Storage::fake('public');
        $page = Page::factory()->create();
        $file = UploadedFile::fake()->image('list-item.jpg');

        $page->addMedia($file)->toMediaCollection('list-item');

        $this->assertCount(1, $page->getMedia('list-item'));
        $this->assertEquals('list-item.jpg', $page->getFirstMedia('list-item')->file_name);
    }

    public function test_can_add_detail_item_desktop_image_to_page()
    {
        Storage::fake('public');
        $page = Page::factory()->create();
        $file = UploadedFile::fake()->image('desktop.jpg');

        $page->addMedia($file)->toMediaCollection('detail-item-desktop');

        $this->assertCount(1, $page->getMedia('detail-item-desktop'));
        $this->assertEquals('desktop.jpg', $page->getFirstMedia('detail-item-desktop')->file_name);
    }

    public function test_can_add_detail_item_mobile_image_to_page()
    {
        Storage::fake('public');
        $page = Page::factory()->create();
        $file = UploadedFile::fake()->image('mobile.jpg');

        $page->addMedia($file)->toMediaCollection('detail-item-mobile');

        $this->assertCount(1, $page->getMedia('detail-item-mobile'));
        $this->assertEquals('mobile.jpg', $page->getFirstMedia('detail-item-mobile')->file_name);
    }

    public function test_list_item_collection_accepts_only_single_file()
    {
        Storage::fake('public');
        $page = Page::factory()->create();
        $file1 = UploadedFile::fake()->image('image1.jpg');
        $file2 = UploadedFile::fake()->image('image2.jpg');

        $page->addMedia($file1)->toMediaCollection('list-item');
        $page->addMedia($file2)->toMediaCollection('list-item');

        $this->assertCount(1, $page->fresh()->getMedia('list-item'));
        $this->assertEquals('image2.jpg', $page->getFirstMedia('list-item')->file_name);
    }

    public function test_detail_item_desktop_collection_accepts_only_single_file()
    {
        Storage::fake('public');
        $page = Page::factory()->create();
        $file1 = UploadedFile::fake()->image('desktop1.jpg');
        $file2 = UploadedFile::fake()->image('desktop2.jpg');

        $page->addMedia($file1)->toMediaCollection('detail-item-desktop');
        $page->addMedia($file2)->toMediaCollection('detail-item-desktop');

        $this->assertCount(1, $page->fresh()->getMedia('detail-item-desktop'));
        $this->assertEquals('desktop2.jpg', $page->getFirstMedia('detail-item-desktop')->file_name);
    }
}
