<?php

namespace Tests\Feature;

use App\Models\BrandStory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandStoryMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_add_list_item_image_to_brand_story()
    {
        Storage::fake('public');
        $brandStory = BrandStory::factory()->create();
        $file = UploadedFile::fake()->image('list-item.jpg');

        $brandStory->addMedia($file)->toMediaCollection('list-item');

        $this->assertCount(1, $brandStory->getMedia('list-item'));
        $this->assertEquals('list-item.jpg', $brandStory->getFirstMedia('list-item')->file_name);
    }

    public function test_can_add_detail_item_desktop_image_to_brand_story()
    {
        Storage::fake('public');
        $brandStory = BrandStory::factory()->create();
        $file = UploadedFile::fake()->image('desktop.jpg');

        $brandStory->addMedia($file)->toMediaCollection('detail-item-desktop');

        $this->assertCount(1, $brandStory->getMedia('detail-item-desktop'));
        $this->assertEquals('desktop.jpg', $brandStory->getFirstMedia('detail-item-desktop')->file_name);
    }

    public function test_can_add_detail_item_mobile_image_to_brand_story()
    {
        Storage::fake('public');
        $brandStory = BrandStory::factory()->create();
        $file = UploadedFile::fake()->image('mobile.jpg');

        $brandStory->addMedia($file)->toMediaCollection('detail-item-mobile');

        $this->assertCount(1, $brandStory->getMedia('detail-item-mobile'));
        $this->assertEquals('mobile.jpg', $brandStory->getFirstMedia('detail-item-mobile')->file_name);
    }

    public function test_list_item_collection_accepts_only_single_file()
    {
        Storage::fake('public');
        $brandStory = BrandStory::factory()->create();
        $file1 = UploadedFile::fake()->image('image1.jpg');
        $file2 = UploadedFile::fake()->image('image2.jpg');

        $brandStory->addMedia($file1)->toMediaCollection('list-item');
        $brandStory->addMedia($file2)->toMediaCollection('list-item');

        $this->assertCount(1, $brandStory->fresh()->getMedia('list-item'));
        $this->assertEquals('image2.jpg', $brandStory->getFirstMedia('list-item')->file_name);
    }

    public function test_detail_item_desktop_collection_accepts_only_single_file()
    {
        Storage::fake('public');
        $brandStory = BrandStory::factory()->create();
        $file1 = UploadedFile::fake()->image('desktop1.jpg');
        $file2 = UploadedFile::fake()->image('desktop2.jpg');

        $brandStory->addMedia($file1)->toMediaCollection('detail-item-desktop');
        $brandStory->addMedia($file2)->toMediaCollection('detail-item-desktop');

        $this->assertCount(1, $brandStory->fresh()->getMedia('detail-item-desktop'));
        $this->assertEquals('desktop2.jpg', $brandStory->getFirstMedia('detail-item-desktop')->file_name);
    }
}
