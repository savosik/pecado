<?php

namespace Tests\Feature\Crm;

use App\Enums\UserQuestionStatus;
use App\Models\PersonalManager;
use App\Models\User;
use App\Models\UserQuestion;
use App\Notifications\UserQuestions\NewQuestionAdminNotification;
use App\Notifications\UserQuestions\QuestionAnsweredNotification;
use App\Services\Notifications\StaffNotifications;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * CRM-раздел «Вопросы клиентов».
 *
 * До него вопросы жили только в админке под правом `user-questions`, а письмо
 * о новом вопросе уходило на список адресов из конфига — менеджеры не знали,
 * что их партнёры о чём-то спрашивают. Предмет проверок: вопрос виден своему
 * менеджеру и не виден чужому, ответ из CRM уходит клиенту, а о новом вопросе
 * персональный менеджер узнаёт письмом со ссылкой в CRM.
 */
class QuestionsTest extends TestCase
{
    use RefreshDatabase, RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $managerProfile;

    private User $client;

    private User $otherManager;

    private User $otherClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $this->managerProfile->id,
            'erp_name' => 'ООО «Ромашка»',
        ]);

        $this->otherManager = User::factory()->create(['email' => 'other@pecado.ru']);
        $this->otherManager->assignRole('sales-manager');
        $otherProfile = PersonalManager::factory()->create(['user_id' => $this->otherManager->id]);
        $this->otherClient = User::factory()->create(['personal_manager_id' => $otherProfile->id]);
    }

    private function salesHead(): User
    {
        $head = User::factory()->create(['email' => 'head@pecado.ru']);
        $head->assignRole('sales-head');

        return $head;
    }

    #[Test]
    #[TestDox('Без права crm-questions.view раздел закрыт')]
    public function section_requires_permission(): void
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo('crm-dashboard.view');

        $this->actingAs($staff)->get('/crm/questions')->assertForbidden();
    }

    #[Test]
    #[TestDox('Менеджер видит вопросы своих партнёров, чужие и гостевые — нет')]
    public function manager_sees_only_own_clients_questions(): void
    {
        $this->restrictManagersToOwnClients();

        $mine = UserQuestion::factory()->create(['user_id' => $this->client->id, 'email' => $this->client->email]);
        UserQuestion::factory()->create(['user_id' => $this->otherClient->id]);
        UserQuestion::factory()->create(['user_id' => null, 'email' => 'guest@example.com']);

        $this->actingAs($this->manager)
            ->get('/crm/questions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Crm/Pages/Questions/Index', false)
                ->has('questions.data', 1)
                ->where('questions.data.0.id', $mine->id)
                ->where('questions.data.0.client.name', 'ООО «Ромашка»')
                ->where('counts.open', 1)
                ->where('canSeeDepartment', false));
    }

    #[Test]
    #[TestDox('Разрез «весь отдел» показывает вопросы всех партнёров и гостей')]
    public function department_scope_shows_everything(): void
    {
        UserQuestion::factory()->create(['user_id' => $this->client->id]);
        UserQuestion::factory()->create(['user_id' => $this->otherClient->id]);
        UserQuestion::factory()->create(['user_id' => null, 'email' => 'guest@example.com']);

        // Своя карточка менеджера есть — по умолчанию разрез «мои».
        $this->actingAs($this->manager)
            ->get('/crm/questions')
            ->assertInertia(fn ($page) => $page->has('questions.data', 1)->where('canSeeDepartment', true));

        $this->actingAs($this->manager)
            ->get('/crm/questions?scope=department')
            ->assertInertia(fn ($page) => $page->has('questions.data', 3)->where('counts.open', 3));

        // РОП без карточки менеджера — сразу весь отдел.
        $this->actingAs($this->salesHead())
            ->get('/crm/questions')
            ->assertInertia(fn ($page) => $page->has('questions.data', 3));
    }

    #[Test]
    #[TestDox('Чипы статусов: по умолчанию — ждущие ответа, отвеченные отдельно')]
    public function status_filter_defaults_to_open(): void
    {
        UserQuestion::factory()->create(['user_id' => $this->client->id]);
        UserQuestion::factory()->inProgress()->create(['user_id' => $this->client->id]);
        UserQuestion::factory()->answered()->create(['user_id' => $this->client->id]);
        UserQuestion::factory()->rejected()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->manager)
            ->get('/crm/questions')
            ->assertInertia(fn ($page) => $page
                ->has('questions.data', 2)
                ->where('filters.status', 'open')
                ->where('counts', ['open' => 2, 'answered' => 1, 'rejected' => 1, 'all' => 4]));

        $this->actingAs($this->manager)
            ->get('/crm/questions?status=answered')
            ->assertInertia(fn ($page) => $page->has('questions.data', 1)->where('questions.data.0.status', 'answered'));

        $this->actingAs($this->manager)
            ->get('/crm/questions?status=all')
            ->assertInertia(fn ($page) => $page->has('questions.data', 4));
    }

    #[Test]
    #[TestDox('Чужой вопрос для менеджера — 404, а не 403')]
    public function foreign_question_is_not_found(): void
    {
        $this->restrictManagersToOwnClients();

        $foreign = UserQuestion::factory()->create(['user_id' => $this->otherClient->id]);

        $this->actingAs($this->manager)->get("/crm/questions/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->manager)
            ->post("/crm/questions/{$foreign->id}/answer", ['answer' => 'Не ваш вопрос'])
            ->assertNotFound();

        $this->assertSame(UserQuestionStatus::NEW, $foreign->fresh()->status);
    }

    #[Test]
    #[TestDox('Открытие карточки переводит новый вопрос «в работу»')]
    public function opening_marks_in_progress(): void
    {
        $question = UserQuestion::factory()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->manager)
            ->get("/crm/questions/{$question->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Crm/Pages/Questions/Show', false)
                ->where('question.status', 'in_progress')
                ->where('question.body', $question->body)
                ->where('canEdit', true));

        $question->refresh();
        $this->assertSame(UserQuestionStatus::IN_PROGRESS, $question->status);
        $this->assertSame($this->manager->id, $question->answered_by_user_id);
    }

    #[Test]
    #[TestDox('Ответ из CRM уходит клиенту письмом и записывает автора')]
    public function answer_notifies_client(): void
    {
        Notification::fake();

        $question = UserQuestion::factory()->inProgress()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->manager)
            ->post("/crm/questions/{$question->id}/answer", ['answer' => 'Доставка в Москву занимает 2–3 рабочих дня.'])
            ->assertRedirect("/crm/questions/{$question->id}");

        $question->refresh();
        $this->assertSame(UserQuestionStatus::ANSWERED, $question->status);
        $this->assertSame('Доставка в Москву занимает 2–3 рабочих дня.', $question->answer);
        $this->assertSame($this->manager->id, $question->answered_by_user_id);
        $this->assertNotNull($question->answered_at);

        Notification::assertSentTo($this->client, QuestionAnsweredNotification::class);
    }

    #[Test]
    #[TestDox('Гостю ответ уходит на адрес из вопроса')]
    public function answer_to_guest_goes_to_their_email(): void
    {
        Notification::fake();

        $question = UserQuestion::factory()->create(['user_id' => null, 'email' => 'guest@example.com']);

        $this->actingAs($this->salesHead())
            ->post("/crm/questions/{$question->id}/answer", ['answer' => 'Минимальный заказ — от 10 000 ₽.'])
            ->assertRedirect();

        Notification::assertSentTo(
            new AnonymousNotifiable,
            QuestionAnsweredNotification::class,
            fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'guest@example.com',
        );
    }

    #[Test]
    #[TestDox('Пустой ответ не принимается')]
    public function empty_answer_is_rejected(): void
    {
        $question = UserQuestion::factory()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->manager)
            ->from("/crm/questions/{$question->id}")
            ->post("/crm/questions/{$question->id}/answer", ['answer' => ''])
            ->assertSessionHasErrors('answer');

        $this->assertSame(UserQuestionStatus::NEW, $question->fresh()->status);
    }

    #[Test]
    #[TestDox('Отклонение — тихое: статус, причина, без письма клиенту')]
    public function reject_is_silent(): void
    {
        Notification::fake();

        $question = UserQuestion::factory()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->manager)
            ->post("/crm/questions/{$question->id}/reject", ['rejected_reason' => 'Спам'])
            ->assertRedirect();

        $question->refresh();
        $this->assertSame(UserQuestionStatus::REJECTED, $question->status);
        $this->assertSame('Спам', $question->rejected_reason);
        Notification::assertNothingSent();
    }

    #[Test]
    #[TestDox('Бейдж меню считает вопросы своих партнёров, ждущие ответа')]
    public function menu_counter_counts_open_own_questions(): void
    {
        UserQuestion::factory()->create(['user_id' => $this->client->id]);
        UserQuestion::factory()->inProgress()->create(['user_id' => $this->client->id]);
        UserQuestion::factory()->answered()->create(['user_id' => $this->client->id]);
        UserQuestion::factory()->create(['user_id' => $this->otherClient->id]);

        $this->actingAs($this->manager)
            ->get('/crm')
            ->assertInertia(fn ($page) => $page->where('crmCounters.questions', 2));
    }

    #[Test]
    #[TestDox('О новом вопросе партнёра персональный менеджер узнаёт письмом со ссылкой в CRM')]
    public function new_question_notifies_personal_manager_with_crm_link(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => ['support@pecado.ru']]);

        $this->actingAs($this->client)->post('/faq/questions', [
            'subject' => 'Когда придёт заказ',
            'body' => 'Подскажите, когда отгрузите заказ 29УТ-011777?',
        ])->assertRedirect();

        $question = UserQuestion::firstOrFail();

        Notification::assertSentTo(
            $this->manager,
            NewQuestionAdminNotification::class,
            fn (NewQuestionAdminNotification $n) => $n->question->is($question)
                && str_ends_with((string) $n->url, "/crm/questions/{$question->id}"),
        );
        Notification::assertNotSentTo($this->otherManager, NewQuestionAdminNotification::class);

        // Общий адрес из конфига получает письмо по-прежнему — со ссылкой в админку.
        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewQuestionAdminNotification::class,
            fn (NewQuestionAdminNotification $n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'support@pecado.ru'
                && $n->url === null,
        );
    }

    #[Test]
    #[TestDox('Менеджер из общего списка адресатов второго письма не получает')]
    public function manager_in_recipients_list_is_not_notified_twice(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => ['Manager@pecado.ru']]);

        $this->actingAs($this->client)->post('/faq/questions', [
            'subject' => 'Тема вопроса',
            'body' => 'Достаточно длинный текст вопроса.',
        ])->assertRedirect();

        Notification::assertNotSentTo($this->manager, NewQuestionAdminNotification::class);
        Notification::assertSentTimes(NewQuestionAdminNotification::class, 1);
    }

    #[Test]
    #[TestDox('Отписавшийся в «Моих уведомлениях» менеджер письма не получает, вопрос в CRM остаётся')]
    public function unsubscribed_manager_is_not_notified(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => []]);
        app(StaffNotifications::class)->save($this->manager, 'staff.question_received', false, null);

        $this->actingAs($this->client)->post('/faq/questions', [
            'subject' => 'Тема вопроса',
            'body' => 'Достаточно длинный текст вопроса.',
        ])->assertRedirect();

        Notification::assertNotSentTo($this->manager, NewQuestionAdminNotification::class);

        $this->actingAs($this->manager)
            ->get('/crm/questions')
            ->assertInertia(fn ($page) => $page->has('questions.data', 1));
    }

    #[Test]
    #[TestDox('Вопрос гостя менеджерам письмом не уходит — владельца нет')]
    public function guest_question_notifies_nobody_in_crm(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => []]);

        $this->post('/faq/questions', [
            'email' => 'guest@example.com',
            'name' => 'Гость',
            'subject' => 'Тема вопроса',
            'body' => 'Достаточно длинный текст вопроса.',
        ])->assertRedirect();

        Notification::assertNotSentTo($this->manager, NewQuestionAdminNotification::class);
        Notification::assertNotSentTo($this->otherManager, NewQuestionAdminNotification::class);
    }
}
