<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Notifications\UserQuestions\NewQuestionAdminNotification;
use App\Notifications\UserQuestions\QuestionReceivedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Адресация вопросов с сайта задана списком, а не ролями.
 *
 * Роль раздаёт права, а не почту: пока адресатов выбирала выборка
 * `User::role(...)`, любая новая роль у сотрудника молча подписывала его
 * на переписку с клиентами — тем же способом, каким письма о заказах
 * уходили всем продажникам разом.
 */
class UserQuestionRecipientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function question_goes_to_the_configured_addresses_only(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => ['support@pecado.ru', 'content@pecado.ru']]);

        $this->post(route('faq.questions.store'), [
            'name' => 'Пётр',
            'email' => 'petr@example.org',
            'subject' => 'Вопрос по товару',
            'body' => 'Есть ли в наличии?',
        ])->assertRedirect();

        Notification::assertSentTimes(NewQuestionAdminNotification::class, 2);

        foreach (['support@pecado.ru', 'content@pecado.ru'] as $address) {
            Notification::assertSentTo(
                new AnonymousNotifiable,
                NewQuestionAdminNotification::class,
                fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === $address,
            );
        }
    }

    #[Test]
    public function role_alone_does_not_subscribe_a_employee_to_client_questions(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => ['support@pecado.ru']]);

        User::factory()->create(['email' => 'boss@pecado.ru'])->assignRole('super-admin');
        User::factory()->create(['email' => 'content.person@pecado.ru'])->assignRole('content-manager');

        $this->post(route('faq.questions.store'), [
            'name' => 'Пётр',
            'email' => 'petr@example.org',
            'subject' => 'Вопрос по товару',
            'body' => 'Есть ли в наличии?',
        ])->assertRedirect();

        Notification::assertSentTimes(NewQuestionAdminNotification::class, 1);
        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewQuestionAdminNotification::class,
            fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'support@pecado.ru',
        );
    }

    #[Test]
    public function empty_list_means_no_letters_but_the_question_is_still_saved(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => []]);

        $this->post(route('faq.questions.store'), [
            'name' => 'Пётр',
            'email' => 'petr@example.org',
            'subject' => 'Вопрос по товару',
            'body' => 'Есть ли в наличии?',
        ])->assertRedirect();

        Notification::assertNotSentTo(new AnonymousNotifiable, NewQuestionAdminNotification::class);

        // Вопрос не теряется: он виден в админке, письмо лишь ускоряет реакцию
        $this->assertDatabaseHas('user_questions', [
            'email' => 'petr@example.org',
            'subject' => 'Вопрос по товару',
        ]);
    }

    #[Test]
    public function the_author_still_gets_a_receipt(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => []]);

        $this->post(route('faq.questions.store'), [
            'name' => 'Пётр',
            'email' => 'petr@example.org',
            'subject' => 'Вопрос по товару',
            'body' => 'Есть ли в наличии?',
        ])->assertRedirect();

        Notification::assertSentTo(
            new AnonymousNotifiable,
            QuestionReceivedNotification::class,
            fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'petr@example.org',
        );
    }

    #[Test]
    public function logged_in_client_gets_the_receipt_at_his_own_address(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => []]);

        $client = User::factory()->create(['email' => 'client@example.org']);

        $this->actingAs($client)
            ->post(route('faq.questions.store'), [
                'subject' => 'Вопрос по заказу',
                'body' => 'Когда приедет?',
            ])->assertRedirect();

        Notification::assertSentTo($client, QuestionReceivedNotification::class);
        $this->assertDatabaseHas('user_questions', [
            'user_id' => $client->id,
            'email' => 'client@example.org',
        ]);
    }
}
