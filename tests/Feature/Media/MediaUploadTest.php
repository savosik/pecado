<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_can_upload_media_attached_to_user()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('avatar.jpg');

        $response = $this->actingAs($user)
            ->postJson('/api/media', [
                'file' => $file,
                'model_type' => User::class,
                'model_id' => $user->id,
                'collection' => 'avatars',
                'tags' => ['profile', 'test']
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'url',
                'tags',
            ]
        ]);

        $this->assertDatabaseHas('media', [
            'model_type' => User::class,
            'model_id' => $user->id,
            'file_name' => 'avatar.jpg',
            'collection_name' => 'avatars',
        ]);
        
        // Assert tags (Spatie tags are polymorphic)
        // We can verify via the response or simple DB check if tags table populated
        $this->assertEquals(2, $user->media->first()->tags->count());
    }

    public function test_can_list_and_filter_media()
    {
        Storage::fake('public');
        $user = User::factory()->create();
        
        $media1 = $user->addMedia(UploadedFile::fake()->image('img1.jpg'))->withCustomProperties(['foo' => 'bar'])->toMediaCollection('default');
        $media2 = $user->addMedia(UploadedFile::fake()->image('img2.jpg'))->toMediaCollection('avatars');
        
        $media1->attachTags(['tag1']);

        // Test list all
        $response = $this->actingAs($user)->getJson('/api/media');
        $response->assertStatus(200)
                 ->assertJsonCount(2, 'data');

        // Test filter by collection
        $response = $this->actingAs($user)->getJson('/api/media?collection=avatars');
        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data')
                 ->assertJsonFragment(['file_name' => 'img2.jpg']);

        // Test filter by tags
        $response = $this->actingAs($user)->getJson('/api/media?tags=tag1');
        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data')
                 ->assertJsonFragment(['file_name' => 'img1.jpg']);
    }

    public function test_can_delete_media()
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $media = $user->addMedia(UploadedFile::fake()->image('del.jpg'))->toMediaCollection();

        $response = $this->actingAs($user)->deleteJson("/api/media/{$media->id}");
        
        $response->assertStatus(200);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }
}
