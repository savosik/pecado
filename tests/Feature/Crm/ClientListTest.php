<?php

namespace Tests\Feature\Crm;

use App\Models\CrmComment;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Рабочий список клиентов: поиск, состояние задач и изоляция скоупа.
 *
 * Изоляция проверяется отдельно на каждом новом входе (поиск по задачам,
 * поиск по комментариям): новый способ найти клиента — это и новый способ
 * увидеть чужого.
 */
class ClientListTest extends TestCase
{
    use RefreshDatabase;

    private User $managerA;

    private User $managerB;

    private PersonalManager $cardA;

    private PersonalManager $cardB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->managerA = User::factory()->create();
        $this->managerA->assignRole('sales-manager');
        $this->cardA = PersonalManager::factory()->create(['user_id' => $this->managerA->id]);

        $this->managerB = User::factory()->create();
        $this->managerB->assignRole('sales-manager');
        $this->cardB = PersonalManager::factory()->create(['user_id' => $this->managerB->id]);
    }

    private function clientOf(PersonalManager $card, array $attrs = []): User
    {
        return User::factory()->create(array_merge(['personal_manager_id' => $card->id], $attrs));
    }

    private function salesHead(): User
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        return $head;
    }

    /**
     * @return list<int>
     */
    private function listedIds(User $actor, array $params = []): array
    {
        $response = $this->actingAs($actor)->get(route('crm.clients.index', $params));
        $response->assertOk();

        $page = $response->viewData('page');

        return array_map(
            static fn (array $row): int => $row['id'],
            $page['props']['clients']['data'],
        );
    }

    #[Test]
    public function search_finds_client_by_task_title(): void
    {
        $target = $this->clientOf($this->cardA, ['name' => 'Ромашка']);
        $other = $this->clientOf($this->cardA, ['name' => 'Лютик']);

        CrmTask::factory()
            ->by($this->managerA)
            ->assignedTo($this->managerA)
            ->on($target)
            ->create(['title' => 'Согласовать отсрочку платежа']);

        $ids = $this->listedIds($this->managerA, ['search' => 'отсрочку']);

        $this->assertSame([$target->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_finds_client_by_comment_body(): void
    {
        $target = $this->clientOf($this->cardA);

        $comment = new CrmComment(['body' => 'Просил прайс на новую коллекцию']);
        $comment->commentable()->associate($target);
        $comment->user_id = $this->managerA->id;
        $comment->save();

        $this->assertSame([$target->id], $this->listedIds($this->managerA, ['search' => 'прайс']));
    }

    #[Test]
    public function search_finds_client_by_normalized_phone(): void
    {
        $target = $this->clientOf($this->cardA, ['phone' => '+7 (495) 111-22-33']);
        $this->clientOf($this->cardA, ['phone' => '+7 (999) 000-00-00']);

        // Ищем слитными цифрами — так номер копируют из 1С и из мессенджера.
        $this->assertSame([$target->id], $this->listedIds($this->managerA, ['search' => '4951112233']));
    }

    #[Test]
    public function search_finds_client_by_order_number(): void
    {
        $target = $this->clientOf($this->cardA);
        $this->clientOf($this->cardA);

        Order::factory()->create([
            'user_id' => $target->id,
            'erp_number' => 'ЗК-004512',
        ]);

        $this->assertSame([$target->id], $this->listedIds($this->managerA, ['search' => '004512']));
    }

    #[Test]
    public function manager_does_not_find_client_by_colleagues_task(): void
    {
        // Общий клиент РОПа не при чём: задача коллеги по СВОЕМУ клиенту не должна
        // вытаскивать клиента в выдачу менеджера, который её не видит.
        $client = $this->clientOf($this->cardA);

        CrmTask::factory()
            ->by($this->managerB)
            ->assignedTo($this->managerB)
            ->on($client)
            ->create(['title' => 'Секретное поручение руководителя']);

        $this->assertSame([], $this->listedIds($this->managerA, ['search' => 'Секретное']));
    }

    #[Test]
    public function sales_head_finds_client_by_any_task(): void
    {
        $client = $this->clientOf($this->cardA);

        CrmTask::factory()
            ->by($this->managerB)
            ->assignedTo($this->managerB)
            ->on($client)
            ->create(['title' => 'Секретное поручение руководителя']);

        $this->assertSame([$client->id], $this->listedIds($this->salesHead(), ['search' => 'Секретное']));
    }

    #[Test]
    public function search_does_not_leak_foreign_client(): void
    {
        $foreign = $this->clientOf($this->cardB, ['name' => 'Чужая Компания']);

        $comment = new CrmComment(['body' => 'уникальнаяметка']);
        $comment->commentable()->associate($foreign);
        $comment->user_id = $this->managerB->id;
        $comment->save();

        $this->assertSame([], $this->listedIds($this->managerA, ['search' => 'уникальнаяметка']));
        $this->assertSame([], $this->listedIds($this->managerA, ['search' => 'Чужая']));
    }

    #[Test]
    public function next_task_column_marks_overdue_and_today(): void
    {
        // Время фиксируем: срок «через 2 часа» после 22:00 уезжает на завтра,
        // и тест разваливался в зависимости от того, когда его запустили.
        Carbon::setTestNow(Carbon::today()->setTime(9, 0));

        $late = $this->clientOf($this->cardA);
        $today = $this->clientOf($this->cardA);
        $empty = $this->clientOf($this->cardA);

        CrmTask::factory()->by($this->managerA)->assignedTo($this->managerA)->on($late)
            ->create(['due_at' => Carbon::now()->subDays(2)]);
        CrmTask::factory()->by($this->managerA)->assignedTo($this->managerA)->on($today)
            ->create(['due_at' => Carbon::now()->addHours(2)]);

        $response = $this->actingAs($this->managerA)->get(route('crm.clients.index', ['sort_by' => 'id', 'sort_order' => 'asc']));
        $rows = collect($response->viewData('page')['props']['clients']['data'])->keyBy('id');

        $this->assertSame('overdue', $rows[$late->id]['tasks']['next']['due_state']);
        $this->assertSame(2, $rows[$late->id]['tasks']['next']['overdue_days']);
        $this->assertSame('today', $rows[$today->id]['tasks']['next']['due_state']);
        $this->assertNull($rows[$empty->id]['tasks']['next']);

        Carbon::setTestNow();
    }

    #[Test]
    public function task_state_filter_selects_only_overdue(): void
    {
        $late = $this->clientOf($this->cardA);
        $future = $this->clientOf($this->cardA);

        CrmTask::factory()->by($this->managerA)->assignedTo($this->managerA)->on($late)
            ->create(['due_at' => Carbon::now()->subDay()]);
        CrmTask::factory()->by($this->managerA)->assignedTo($this->managerA)->on($future)
            ->create(['due_at' => Carbon::now()->addMonth()]);

        $this->assertSame([$late->id], $this->listedIds($this->managerA, ['task_state' => 'overdue']));
    }

    #[Test]
    public function task_state_none_lists_clients_without_next_step(): void
    {
        $covered = $this->clientOf($this->cardA);
        $uncovered = $this->clientOf($this->cardA);

        CrmTask::factory()->by($this->managerA)->assignedTo($this->managerA)->on($covered)->create();

        $this->assertSame([$uncovered->id], $this->listedIds($this->managerA, ['task_state' => 'none']));
    }

    #[Test]
    public function activity_hint_falls_back_to_last_comment(): void
    {
        $client = $this->clientOf($this->cardA);

        foreach (['первый', 'последний'] as $body) {
            $comment = new CrmComment(['body' => $body]);
            $comment->commentable()->associate($client);
            $comment->user_id = $this->managerA->id;
            $comment->save();
        }

        $response = $this->actingAs($this->managerA)->get(route('crm.clients.index'));
        $row = $response->viewData('page')['props']['clients']['data'][0];

        $this->assertSame('comment', $row['activity']['kind']);
        $this->assertSame('последний', $row['activity']['text']);
    }

    #[Test]
    public function client_list_query_count_does_not_grow_with_page_size(): void
    {
        // Пятнадцать клиентов, у каждого задача и комментарий — если гидрация
        // страницы станет циклом, число запросов поедет вслед за per_page.
        foreach (range(1, 15) as $i) {
            $client = $this->clientOf($this->cardA);
            CrmTask::factory()->by($this->managerA)->assignedTo($this->managerA)->on($client)->create();
            $comment = new CrmComment(['body' => 'заметка '.$i]);
            $comment->commentable()->associate($client);
            $comment->user_id = $this->managerA->id;
            $comment->save();
        }

        $count = function (int $perPage): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($this->managerA)
                ->get(route('crm.clients.index', ['per_page' => $perPage]))
                ->assertOk();

            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // Прогрев: первый запрос заполняет кэш прав Spatie, и без него замер
        // показал бы разницу, не имеющую отношения к размеру страницы.
        $count(5);

        $small = $count(5);
        $large = $count(15);

        $this->assertSame($small, $large, "Запросов на страницу 5: {$small}, на 15: {$large} — появился N+1");
    }

    #[Test]
    public function manager_cannot_filter_by_foreign_manager_id(): void
    {
        $this->clientOf($this->cardA);
        $this->clientOf($this->cardB);

        $this->actingAs($this->managerA)
            ->get(route('crm.clients.index', ['manager_id' => $this->cardB->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 1)
                ->where('filters.manager_id', null)
            );
    }
}
