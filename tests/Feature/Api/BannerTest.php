<?php

namespace Tests\Feature\Api;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_banners()
    {
        Banner::factory()->count(3)->create();

        $response = $this->getJson('/api/banners');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'link',
                    ]
                ],
                'links',
                'meta'
            ]);
    }

    public function test_can_show_banner()
    {
        $banner = Banner::factory()->create();

        $response = $this->getJson('/api/banners/' . $banner->id);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $banner->id,
                    'title' => $banner->title,
                    'link' => $banner->link,
                ]
            ]);
    }
}
