<?php

namespace Tests\Feature;

use App\Models\ProductSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductSelectionMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_add_desktop_image_to_product_selection()
    {
        Storage::fake('public');
        $selection = ProductSelection::factory()->create();
        $file = UploadedFile::fake()->image('desktop.jpg');

        $selection->addMedia($file)->toMediaCollection('desktop');

        $this->assertCount(1, $selection->getMedia('desktop'));
        $this->assertEquals('desktop.jpg', $selection->getFirstMedia('desktop')->file_name);
    }

    public function test_can_add_mobile_image_to_product_selection()
    {
        Storage::fake('public');
        $selection = ProductSelection::factory()->create();
        $file = UploadedFile::fake()->image('mobile.jpg');

        $selection->addMedia($file)->toMediaCollection('mobile');

        $this->assertCount(1, $selection->getMedia('mobile'));
        $this->assertEquals('mobile.jpg', $selection->getFirstMedia('mobile')->file_name);
    }

    public function test_desktop_collection_accepts_only_single_file()
    {
        Storage::fake('public');
        $selection = ProductSelection::factory()->create();
        $file1 = UploadedFile::fake()->image('desktop1.jpg');
        $file2 = UploadedFile::fake()->image('desktop2.jpg');

        $selection->addMedia($file1)->toMediaCollection('desktop');
        $selection->addMedia($file2)->toMediaCollection('desktop');

        $this->assertCount(1, $selection->fresh()->getMedia('desktop'));
        $this->assertEquals('desktop2.jpg', $selection->getFirstMedia('desktop')->file_name);
    }
}
