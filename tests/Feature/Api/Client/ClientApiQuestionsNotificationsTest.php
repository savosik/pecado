<?php

namespace Tests\Feature\Api\Client;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserQuestion;
use App\Notifications\UserQuestions\NewQuestionAdminNotification;
use App\Notifications\UserQuestions\QuestionReceivedNotification;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiQuestionsNotificationsTest extends ClientApiTestCase
{
    #[Test]
    #[TestDox('Вопрос из API виден в кабинете, письма ушли по тем же правилам, повтор ключа не дублирует')]
    public function questions(): void
    {
        Notification::fake();
        config(['notifications.mail.user_question_recipients' => ['support@pecado.ru']]);

        $this->api('POST', '/questions', ['subject' => 'Тема', 'body' => 'коротко'])->assertStatus(422)->assertJsonPath('errors.0.field', 'body');

        $payload = ['subject' => 'Объединить заказы', 'body' => 'Просьба отправить заказы 101 и 102 одной машиной.'];
        $created = $this->api('POST', '/questions', $payload, ['Idempotency-Key' => 'q-1'])->assertStatus(201)
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.subject', 'Объединить заказы');
        $id = $created->json('data.id');

        $this->api('POST', '/questions', $payload, ['Idempotency-Key' => 'q-1'])->assertJsonPath('data.id', $id);
        $this->assertSame(1, UserQuestion::count());

        Notification::assertSentTo($this->client, QuestionReceivedNotification::class);
        Notification::assertSentOnDemand(NewQuestionAdminNotification::class);

        $this->actingAs($this->client)->get('/cabinet/questions')->assertOk()
            ->assertInertia(fn ($page) => $page->where('questions.data.0.subject', 'Объединить заказы'));

        UserQuestion::find($id)->update(['answer' => 'Отправим одной машиной.', 'status' => 'answered', 'answered_at' => now()]);
        $this->api('GET', "/questions/{$id}")->assertOk()->assertJsonPath('data.answer', 'Отправим одной машиной.')->assertJsonPath('data.status', 'answered');
        $this->api('GET', '/questions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.has_answer', true);

        $foreign = UserQuestion::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->api('GET', "/questions/{$foreign->id}")->assertStatus(404);
    }

    #[Test]
    #[TestDox('Уведомления: матрица, включение типа как из кабинета, возврат к умолчанию удаляет строку, неизвестный ключ — 422')]
    public function notifications(): void
    {
        $list = $this->api('GET', '/notifications')->assertOk();
        $rows = collect($list->json('data.rows'));
        $this->assertTrue($rows->isNotEmpty());
        $row = $rows->firstWhere('key', 'orders.status_changed');
        $this->assertFalse($row['enabled'], 'клиентские уведомления выключены умолчанием');

        $this->api('PUT', '/notifications/orders.status_changed', ['is_enabled' => true, 'destinations' => [['type' => 'login']]])
            ->assertOk();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $this->client->id, 'occasion_key' => 'orders.status_changed', 'is_enabled' => true, 'changed_by_client' => true]);

        $cabinet = collect($this->actingAs($this->client)->getJson('/cabinet/notifications/data')->assertOk()->json('rows'))->firstWhere('key', 'orders.status_changed');
        $this->assertTrue($cabinet['enabled']);
        $this->assertSame('login', $cabinet['destinations'][0]['type']);

        // Возврат к умолчанию (те же адресаты и флаг, что в каталоге) — строка исчезает, а не пишется «выключено»
        $defaults = array_map(fn (array $d) => array_intersect_key($d, ['type' => 1, 'email' => 1]), $row['destinations']);
        $this->api('PUT', '/notifications/orders.status_changed', ['is_enabled' => false, 'destinations' => $defaults])->assertOk();
        $this->assertSame(0, NotificationPreference::where('occasion_key', 'orders.status_changed')->count());

        $this->api('PUT', '/notifications/system.question_received', ['is_enabled' => true, 'destinations' => []])->assertStatus(422);
        $this->api('PUT', '/notifications/orders.status_changed', ['is_enabled' => true, 'destinations' => [['type' => 'email', 'email' => 'не-почта']]])->assertStatus(422);

        $this->api('PUT', '/notifications/marketing', ['enabled' => false])->assertOk();
        $extras = collect($this->api('GET', '/notifications')->json('data.extras'));
        $this->assertFalse($extras->firstWhere('key', 'extra.campaigns')['enabled']);
    }
}
