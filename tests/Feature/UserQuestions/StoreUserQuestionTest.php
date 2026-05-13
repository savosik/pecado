<?php

namespace Tests\Feature\UserQuestions;

use App\Enums\UserQuestionStatus;
use App\Models\User;
use App\Models\UserQuestion;
use App\Notifications\UserQuestions\NewQuestionAdminNotification;
use App\Notifications\UserQuestions\QuestionReceivedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreUserQuestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Notification::fake();
    }

    public function test_guest_can_submit_question(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('content-manager');

        $response = $this->post('/faq/questions', [
            'email' => 'guest@example.com',
            'name' => 'Иван',
            'subject' => 'Вопрос про доставку',
            'body' => 'Сколько по времени идёт доставка в Москву?',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('user_questions', [
            'email' => 'guest@example.com',
            'name' => 'Иван',
            'subject' => 'Вопрос про доставку',
            'status' => UserQuestionStatus::NEW->value,
            'user_id' => null,
        ]);

        Notification::assertSentOnDemand(QuestionReceivedNotification::class);
        Notification::assertSentTo($manager, NewQuestionAdminNotification::class);
    }

    public function test_authenticated_user_email_is_taken_from_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['email' => 'real@example.com', 'name' => 'Реальное Имя']);

        $this->actingAs($user)->post('/faq/questions', [
            'email' => 'hack@evil.com',
            'name' => 'Hacker',
            'subject' => 'Тема',
            'body' => 'Содержание вопроса от зареганного.',
        ])->assertRedirect();

        $question = UserQuestion::firstOrFail();
        $this->assertSame('real@example.com', $question->email);
        $this->assertSame('Реальное Имя', $question->name);
        $this->assertSame($user->id, $question->user_id);
        Notification::assertSentTo($user, QuestionReceivedNotification::class);
    }

    public function test_honeypot_blocks_submission(): void
    {
        $response = $this->post('/faq/questions', [
            'email' => 'guest@example.com',
            'subject' => 'Тема вопроса',
            'body' => 'Текст вопроса от бота.',
            'website' => 'https://spam.example',
        ]);

        $response->assertSessionHasErrors('website');
        $this->assertDatabaseCount('user_questions', 0);
    }

    public function test_email_required_for_guests(): void
    {
        $response = $this->post('/faq/questions', [
            'subject' => 'Тема вопроса',
            'body' => 'Достаточно длинный текст вопроса.',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('user_questions', 0);
    }

    public function test_file_too_big_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('big.pdf', 11_000, 'application/pdf'); // 11 MB

        $response = $this->post('/faq/questions', [
            'email' => 'guest@example.com',
            'subject' => 'Тема',
            'body' => 'Достаточный текст вопроса для прохождения min:10.',
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('user_questions', 0);
    }

    public function test_invalid_mime_type_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $response = $this->post('/faq/questions', [
            'email' => 'guest@example.com',
            'subject' => 'Тема',
            'body' => 'Достаточный текст вопроса для прохождения min:10.',
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('user_questions', 0);
    }

    public function test_attachment_is_saved_via_media_library(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $file = UploadedFile::fake()->image('screenshot.jpg', 800, 600);

        $this->post('/faq/questions', [
            'email' => 'guest@example.com',
            'subject' => 'Вопрос с файлом',
            'body' => 'Прикрепил скриншот к вопросу.',
            'file' => $file,
        ])->assertRedirect();

        $question = UserQuestion::firstOrFail();
        $media = $question->getFirstMedia('attachment');
        $this->assertNotNull($media);
        $this->assertSame('screenshot.jpg', $media->file_name);
    }

    public function test_rate_limit_blocks_after_five_requests_per_hour(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/faq/questions', [
                'email' => "g{$i}@example.com",
                'subject' => 'Тема',
                'body' => 'Достаточно длинный текст вопроса.',
            ])->assertRedirect();
        }

        $sixth = $this->post('/faq/questions', [
            'email' => 'g6@example.com',
            'subject' => 'Тема',
            'body' => 'Достаточно длинный текст вопроса.',
        ]);

        $sixth->assertStatus(429);
    }
}
