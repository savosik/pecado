<?php

namespace Tests\Feature\UserQuestions;

use App\Enums\UserQuestionStatus;
use App\Models\User;
use App\Models\UserQuestion;
use App\Notifications\UserQuestions\QuestionAnsweredNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserQuestionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    public function test_user_without_admin_role_is_redirected(): void
    {
        $regular = User::factory()->create();

        $this->actingAs($regular)
            ->get('/admin/user-questions')
            ->assertRedirect('/');
    }

    public function test_user_with_role_but_no_permission_gets_403(): void
    {
        $regular = User::factory()->create();
        $regular->assignRole('catalogist');

        $this->actingAs($regular)
            ->get('/admin/user-questions')
            ->assertForbidden();
    }

    public function test_admin_can_view_index(): void
    {
        UserQuestion::factory()->create();

        $this->actingAs($this->admin)
            ->get('/admin/user-questions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Pages/UserQuestions/Index', false)
                ->has('questions.data', 1)
            );
    }

    public function test_opening_question_auto_sets_in_progress(): void
    {
        $question = UserQuestion::factory()->create([
            'status' => UserQuestionStatus::NEW,
        ]);

        $this->actingAs($this->admin)
            ->get("/admin/user-questions/{$question->id}")
            ->assertOk();

        $this->assertSame(
            UserQuestionStatus::IN_PROGRESS,
            $question->fresh()->status,
        );
    }

    public function test_answer_sends_notification_to_guest_email(): void
    {
        Notification::fake();
        $question = UserQuestion::factory()->create([
            'email' => 'guest@example.com',
            'status' => UserQuestionStatus::IN_PROGRESS,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/user-questions/{$question->id}/answer", [
                'answer' => 'Спасибо за вопрос. Доставка занимает 2-3 рабочих дня.',
            ])
            ->assertRedirect();

        $question->refresh();
        $this->assertSame(UserQuestionStatus::ANSWERED, $question->status);
        $this->assertNotNull($question->answer);
        $this->assertSame($this->admin->id, $question->answered_by_user_id);

        Notification::assertSentOnDemand(QuestionAnsweredNotification::class);
    }

    public function test_answer_sends_notification_to_registered_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $question = UserQuestion::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/user-questions/{$question->id}/answer", [
                'answer' => 'Отвечаем зарегистрированному пользователю.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($user, QuestionAnsweredNotification::class);
    }

    public function test_reject_does_not_notify_user(): void
    {
        Notification::fake();
        $question = UserQuestion::factory()->create([
            'email' => 'spammer@example.com',
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/user-questions/{$question->id}/reject", [
                'rejected_reason' => 'Спам',
            ])
            ->assertRedirect();

        $question->refresh();
        $this->assertSame(UserQuestionStatus::REJECTED, $question->status);
        $this->assertSame('Спам', $question->rejected_reason);

        Notification::assertNothingSent();
    }

    public function test_admin_can_delete_question(): void
    {
        $question = UserQuestion::factory()->create();

        $this->actingAs($this->admin)
            ->delete("/admin/user-questions/{$question->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('user_questions', ['id' => $question->id]);
    }
}
