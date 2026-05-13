<?php

namespace Tests\Feature\UserQuestions;

use App\Models\User;
use App\Models\UserQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CabinetQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_own_questions(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserQuestion::factory()->count(2)->create([
            'user_id' => $alice->id,
            'email' => $alice->email,
        ]);
        UserQuestion::factory()->create([
            'user_id' => $bob->id,
            'email' => $bob->email,
        ]);

        $this->actingAs($alice)
            ->get('/cabinet/questions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/Questions/Index')
                ->has('questions.data', 2)
            );
    }

    public function test_user_cannot_view_other_user_question(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $bobsQuestion = UserQuestion::factory()->create([
            'user_id' => $bob->id,
            'email' => $bob->email,
        ]);

        $this->actingAs($alice)
            ->get("/cabinet/questions/{$bobsQuestion->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_download_other_user_attachment(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $bobsQuestion = UserQuestion::factory()->create([
            'user_id' => $bob->id,
            'email' => $bob->email,
        ]);

        $this->actingAs($alice)
            ->get("/cabinet/questions/{$bobsQuestion->id}/attachment")
            ->assertNotFound();
    }

    public function test_guest_cannot_access_cabinet_questions(): void
    {
        $this->get('/cabinet/questions')->assertRedirect('/login');
    }
}
