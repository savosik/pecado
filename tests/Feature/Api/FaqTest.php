<?php

namespace Tests\Feature\Api;

use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_faqs()
    {
        Faq::factory()->count(3)->create();

        $response = $this->getJson('/api/faqs');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'content',
                    ]
                ],
                'links',
                'meta'
            ]);
    }

    public function test_can_show_faq()
    {
        $faq = Faq::factory()->create();

        $response = $this->getJson('/api/faqs/' . $faq->id);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $faq->id,
                    'title' => $faq->title,
                    'content' => $faq->content,
                ]
            ]);
    }
}
