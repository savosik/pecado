<?php

namespace Tests\Feature;

use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PromotionMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_add_list_item_image_to_promotion()
    {
        Storage::fake('public');
        $promotion = Promotion::factory()->create();
        $file = UploadedFile::fake()->image('list-item.jpg');

        $promotion->addMedia($file)->toMediaCollection('list-item');

        $this->assertCount(1, $promotion->getMedia('list-item'));
        $this->assertEquals('list-item.jpg', $promotion->getFirstMedia('list-item')->file_name);
    }

    public function test_can_add_detail_item_desktop_image_to_promotion()
    {
        Storage::fake('public');
        $promotion = Promotion::factory()->create();
        $file = UploadedFile::fake()->image('desktop.jpg');

        $promotion->addMedia($file)->toMediaCollection('detail-item-desktop');

        $this->assertCount(1, $promotion->getMedia('detail-item-desktop'));
        $this->assertEquals('desktop.jpg', $promotion->getFirstMedia('detail-item-desktop')->file_name);
    }

    public function test_can_add_detail_item_mobile_image_to_promotion()
    {
        Storage::fake('public');
        $promotion = Promotion::factory()->create();
        $file = UploadedFile::fake()->image('mobile.jpg');

        $promotion->addMedia($file)->toMediaCollection('detail-item-mobile');

        $this->assertCount(1, $promotion->getMedia('detail-item-mobile'));
        $this->assertEquals('mobile.jpg', $promotion->getFirstMedia('detail-item-mobile')->file_name);
    }

    public function test_list_item_collection_accepts_only_single_file()
    {
        Storage::fake('public');
        $promotion = Promotion::factory()->create();
        $file1 = UploadedFile::fake()->image('image1.jpg');
        $file2 = UploadedFile::fake()->image('image2.jpg');

        $promotion->addMedia($file1)->toMediaCollection('list-item');
        $promotion->addMedia($file2)->toMediaCollection('list-item');

        $this->assertCount(1, $promotion->fresh()->getMedia('list-item'));
        $this->assertEquals('image2.jpg', $promotion->getFirstMedia('list-item')->file_name);
    }

    public function test_detail_item_desktop_collection_accepts_only_single_file()
    {
        Storage::fake('public');
        $promotion = Promotion::factory()->create();
        $file1 = UploadedFile::fake()->image('desktop1.jpg');
        $file2 = UploadedFile::fake()->image('desktop2.jpg');

        $promotion->addMedia($file1)->toMediaCollection('detail-item-desktop');
        $promotion->addMedia($file2)->toMediaCollection('detail-item-desktop');

        $this->assertCount(1, $promotion->fresh()->getMedia('detail-item-desktop'));
        $this->assertEquals('desktop2.jpg', $promotion->getFirstMedia('detail-item-desktop')->file_name);
    }
}
