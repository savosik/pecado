<?php

namespace App\Services\Crm;

use App\Models\CrmCall;
use App\Models\CrmComment;
use App\Models\CrmEmail;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Сквозная лента клиента: всё, что происходило с ним и вокруг него.
 *
 * Источников пять: что менеджеры написали (комментарии, задачи, письма) и что
 * клиент сделал (заказы, реализации). Все сводятся к одной форме записи, а не
 * заводят по вкладке: карточка клиента должна показывать одну хронологию,
 * а не пять лент с разным поведением.
 *
 * Документы отличаются от записей менеджеров только флагом `system`: у них нет
 * автора и их нельзя править. Отдельной формой они не заводятся — иначе фронт
 * рисовал бы ленту двумя разными компонентами и рассинхронизировался бы в первом
 * же изменении.
 */
class ClientTimelineService
{
    /**
     * Форма записи ленты. Единая для всех типов источников, чтобы фронт рисовал
     * ленту одним компонентом.
     */
    private const TYPE_COMMENT = 'comment';

    private const TYPE_TASK = 'task';

    private const TYPE_EMAIL = 'email';

    private const TYPE_ORDER = 'order';

    private const TYPE_SHIPMENT = 'shipment';

    private const TYPE_CALL = 'call';

    /**
     * Все типы записей ленты — для валидации фильтра `types[]`.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_COMMENT,
            self::TYPE_TASK,
            self::TYPE_EMAIL,
            self::TYPE_CALL,
            self::TYPE_ORDER,
            self::TYPE_SHIPMENT,
        ];
    }

    public function __construct(
        private readonly CrmTaskService $tasks,
        private readonly CrmEmailService $emails,
        private readonly CrmCallService $calls,
    ) {}

    /**
     * Лента клиента: записи менеджеров и документы клиента в одной хронологии.
     *
     * Источники сливаются UNION-ом по ключам (тип, id, дата), и только страница
     * результата догружается моделями. Альтернатива — вычитать все источники целиком
     * и слить в памяти — на клиенте с длинной историей означала бы тянуть сотни
     * записей ради двадцати показанных.
     *
     * @param  list<string>|null  $types  фильтр источников; null — все
     * @param  int|string|null  $organizationId  фильтр документов по нашей организации:
     *                                           id, 'none' — без организации, null — не фильтровать
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forClient(
        User $client,
        User $viewer,
        int $perPage = 20,
        ?array $types = null,
        int|string|null $organizationId = null,
    ): LengthAwarePaginator {
        $clientId = (int) $client->getKey();
        $wanted = $types === null || $types === []
            ? self::types()
            : array_values(array_intersect(self::types(), $types));

        // Фильтр по организации — про документы: у комментария, задачи, письма и
        // звонка юрлица нет. Поэтому при активном фильтре записи менеджеров из ленты
        // уходят целиком: показывать их «заодно» значило бы врать, что они относятся
        // к выбранной организации.
        $byOrganization = $organizationId !== null && $organizationId !== '';

        if ($byOrganization) {
            $wanted = array_values(array_intersect($wanted, [self::TYPE_ORDER, self::TYPE_SHIPMENT]));
        }

        $sources = [];

        if (in_array(self::TYPE_COMMENT, $wanted, true)) {
            $sources[] = DB::table('crm_comments')
                ->selectRaw("'".self::TYPE_COMMENT."' as source, id, created_at as happened_at, is_pinned")
                ->where('client_user_id', $clientId)
                ->whereNull('deleted_at');
        }

        if (in_array(self::TYPE_TASK, $wanted, true)) {
            $tasks = DB::table('crm_tasks')
                ->selectRaw("'".self::TYPE_TASK."' as source, id, created_at as happened_at, 0 as is_pinned")
                ->where('client_user_id', $clientId)
                ->whereNull('deleted_at');

            // Задачи коллег по общему клиенту рядовому менеджеру не показываем — то же
            // правило, что в CrmTaskPolicy: поручения между сотрудниками не публичная лента.
            if (! $viewer->can('crm-clients-all.view')) {
                $tasks->where(fn ($query) => $query
                    ->where('author_id', $viewer->getKey())
                    ->orWhere('assignee_id', $viewer->getKey()));
            }

            $sources[] = $tasks;
        }

        if (in_array(self::TYPE_EMAIL, $wanted, true)) {
            // Черновики в ленту не идут: письмо становится событием в жизни клиента,
            // только когда его отправили.
            $sources[] = DB::table('crm_emails')
                ->selectRaw("'".self::TYPE_EMAIL."' as source, id, created_at as happened_at, 0 as is_pinned")
                ->where('client_user_id', $clientId)
                ->whereIn('status', ['queued', 'sent', 'failed']);
        }

        if (in_array(self::TYPE_CALL, $wanted, true)) {
            // Звонок доступен всем, кто видит клиента, — как комментарий, а не как
            // задача: разговор с клиентом это общий факт, а не поручение.
            $sources[] = DB::table('crm_calls')
                ->selectRaw("'".self::TYPE_CALL."' as source, id, COALESCE(started_at, created_at) as happened_at, 0 as is_pinned")
                ->where('client_user_id', $clientId)
                ->whereNull('deleted_at');
        }

        if (in_array(self::TYPE_ORDER, $wanted, true)) {
            // Бизнес-дата 1С, а не created_at: вся историческая база импортирована
            // разом, и по created_at заказы 2024 года встали бы поверх свежих записей.
            // whereNull('deleted_at') явно — DB::table() не применяет SoftDeletes.
            $orderSource = DB::table('orders')
                ->selectRaw("'".self::TYPE_ORDER."' as source, id, COALESCE(erp_created_at, created_at) as happened_at, 0 as is_pinned")
                ->where('user_id', $clientId)
                ->whereNull('deleted_at');

            $sources[] = $byOrganization
                ? $this->whereOrganization($orderSource, $organizationId)
                : $orderSource;
        }

        if (in_array(self::TYPE_SHIPMENT, $wanted, true)) {
            $shipmentSource = DB::table('shipments')
                ->selectRaw("'".self::TYPE_SHIPMENT."' as source, id, COALESCE(erp_created_at, date, created_at) as happened_at, 0 as is_pinned")
                ->where('user_id', $clientId)
                ->whereNull('deleted_at');

            $sources[] = $byOrganization
                ? $this->whereOrganization($shipmentSource, $organizationId)
                : $shipmentSource;
        }

        if ($sources === []) {
            return $this->emptyPage($perPage);
        }

        $union = array_shift($sources);

        foreach ($sources as $source) {
            $union->unionAll($source);
        }

        $paginator = DB::query()
            ->fromSub($union, 'timeline')
            ->orderByDesc('is_pinned')
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            // Четвёртый ключ: id уникален внутри источника, но не между ними —
            // без него страницы «плавали» бы на записях с одинаковой датой.
            ->orderBy('source')
            ->paginate($perPage);

        return $this->hydrate($paginator, $viewer);
    }

    /**
     * Сузить источник документов до одной нашей организации.
     *
     * 'none' — документы без организации: в переходный период их большинство,
     * и отобрать нужно именно их, а не «все прочие».
     */
    private function whereOrganization(\Illuminate\Database\Query\Builder $source, int|string $organizationId): \Illuminate\Database\Query\Builder
    {
        return $organizationId === 'none'
            ? $source->whereNull('organization_id')
            : $source->where('organization_id', (int) $organizationId);
    }

    /**
     * Пустая страница — когда фильтр не оставил ни одного источника.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function emptyPage(int $perPage): LengthAwarePaginator
    {
        return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1, [
            'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
        ]);
    }

    /**
     * Догрузить страницу ключей настоящими моделями.
     *
     * @param  \Illuminate\Pagination\LengthAwarePaginator<int, \stdClass>  $paginator
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function hydrate(\Illuminate\Pagination\LengthAwarePaginator $paginator, User $viewer): LengthAwarePaginator
    {
        $rows = collect($paginator->items());

        $comments = CrmComment::query()
            ->whereIn('id', $rows->where('source', self::TYPE_COMMENT)->pluck('id')->all())
            ->with(['author:id,name', 'commentable'])
            ->withCount($this->attachmentsCount())
            ->get()
            ->keyBy('id');

        $tasks = CrmTask::query()
            ->whereIn('id', $rows->where('source', self::TYPE_TASK)->pluck('id')->all())
            ->with(['author:id,name', 'assignee:id,name', 'related'])
            ->withCount($this->attachmentsCount())
            ->get()
            ->keyBy('id');

        $emails = CrmEmail::query()
            ->whereIn('id', $rows->where('source', self::TYPE_EMAIL)->pluck('id')->all())
            ->with(['author:id,name', 'related'])
            ->withCount($this->attachmentsCount())
            ->get()
            ->keyBy('id');

        $calls = CrmCall::query()
            ->whereIn('id', $rows->where('source', self::TYPE_CALL)->pluck('id')->all())
            ->with(['author:id,name', 'related'])
            ->withCount($this->attachmentsCount())
            ->get()
            ->keyBy('id');

        // Организация и склад грузятся вместе с документом: без них лента и вкладки
        // «Заказы»/«Реализации» делали бы по запросу на строку ради одной подписи.
        $orders = Order::query()
            ->whereIn('id', $rows->where('source', self::TYPE_ORDER)->pluck('id')->all())
            ->with(['organization:id,name,is_stub', 'warehouse:id,name'])
            ->withCount('items')
            ->get()
            ->keyBy('id');

        $shipments = Shipment::query()
            ->whereIn('id', $rows->where('source', self::TYPE_SHIPMENT)->pluck('id')->all())
            ->with(['organization:id,name,is_stub', 'warehouse:id,name'])
            ->withCount('items')
            ->get()
            ->keyBy('id');

        /** @var LengthAwarePaginator<int, array<string, mixed>> $hydrated */
        $hydrated = $paginator->through(function (object $row) use ($comments, $tasks, $emails, $calls, $orders, $shipments, $viewer): array {
            $id = (int) $row->id;

            // Неизвестный источник уходит в заглушку, а не роняет ленту: между
            // выкаткой кода и прогоном миграции состав источников может разойтись.
            return match ($row->source) {
                self::TYPE_COMMENT => ($comment = $comments->get($id)) instanceof CrmComment
                    ? $this->commentEntry($comment, $viewer)
                    : $this->missingEntry(self::TYPE_COMMENT, $id),
                self::TYPE_TASK => ($task = $tasks->get($id)) instanceof CrmTask
                    ? $this->taskEntry($task, $viewer)
                    : $this->missingEntry(self::TYPE_TASK, $id),
                self::TYPE_EMAIL => ($email = $emails->get($id)) instanceof CrmEmail
                    ? $this->emailEntry($email, $viewer)
                    : $this->missingEntry(self::TYPE_EMAIL, $id),
                self::TYPE_CALL => ($call = $calls->get($id)) instanceof CrmCall
                    ? $this->callEntry($call, $viewer)
                    : $this->missingEntry(self::TYPE_CALL, $id),
                self::TYPE_ORDER => ($order = $orders->get($id)) instanceof Order
                    ? $this->orderEntry($order, $viewer)
                    : $this->missingEntry(self::TYPE_ORDER, $id),
                self::TYPE_SHIPMENT => ($shipment = $shipments->get($id)) instanceof Shipment
                    ? $this->shipmentEntry($shipment, $viewer)
                    : $this->missingEntry(self::TYPE_SHIPMENT, $id),
                default => $this->missingEntry((string) $row->source, $id),
            };
        });

        return $hydrated;
    }

    /**
     * Звонок в общей форме записи ленты.
     *
     * @return array<string, mixed>
     */
    public function callEntry(CrmCall $call, User $viewer): array
    {
        $happened = $call->started_at ?? $call->created_at;

        return [
            'type' => self::TYPE_CALL,
            'id' => (int) $call->getKey(),
            'happened_at' => $happened?->toIso8601String(),
            'happened_at_label' => $happened?->format('d.m.Y H:i'),
            'author' => [
                'id' => (int) $call->user_id,
                'name' => $call->author->name,
            ],
            'title' => $call->direction->label().': '.$call->result->label(),
            'excerpt' => $call->summary === null ? null : Str::limit($call->summary, 300),
            'entity' => $call->related instanceof Model
                ? CrmEntityMap::describe($call->related, $viewer)
                : null,
            'call' => $this->calls->payload($call, $viewer),
            'attachments_count' => (int) ($call->attachments_count ?? 0),
            'can' => [
                'update' => $viewer->can('update', $call),
                'delete' => $viewer->can('delete', $call),
            ],
        ];
    }

    /**
     * Заказ в общей форме записи ленты.
     *
     * Автора нет и правки нет: документ пришёл из 1С, а не написан менеджером.
     * Флаг `system` даёт фронту отличить «что случилось» от «что записали».
     *
     * @return array<string, mixed>
     */
    public function orderEntry(Order $order, User $viewer): array
    {
        $happened = $order->erp_created_at ?? $order->created_at;

        return [
            'type' => self::TYPE_ORDER,
            'id' => (int) $order->getKey(),
            'system' => true,
            'happened_at' => $happened?->toIso8601String(),
            'happened_at_label' => $happened?->format('d.m.Y H:i'),
            'author' => null,
            'title' => 'Заказ №'.($order->erp_number ?: $order->number ?: $order->getKey()),
            'excerpt' => $order->comment,
            'amount_label' => $this->money((float) $order->total_amount, $order->currency_code),
            'status_label' => $order->status->label(),
            'status_color' => $order->status->color(),
            'items_count' => (int) ($order->items_count ?? 0),
            'organization' => $this->organization($order),
            'warehouse' => $this->warehouse($order),
            'entity' => CrmEntityMap::describe($order, $viewer),
            'attachments_count' => 0,
            'can' => ['update' => false, 'delete' => false],
        ];
    }

    /**
     * Реализация в общей форме записи ленты.
     *
     * @return array<string, mixed>
     */
    public function shipmentEntry(Shipment $shipment, User $viewer): array
    {
        $happened = $shipment->erp_created_at ?? $shipment->date ?? $shipment->created_at;

        return [
            'type' => self::TYPE_SHIPMENT,
            'id' => (int) $shipment->getKey(),
            'system' => true,
            'happened_at' => $happened?->toIso8601String(),
            'happened_at_label' => $happened?->format('d.m.Y H:i'),
            'author' => null,
            'title' => 'Реализация №'.($shipment->erp_number ?: $shipment->number ?: $shipment->getKey()),
            'excerpt' => null,
            'amount_label' => $this->money((float) $shipment->total_amount, $shipment->currency_code),
            'status_label' => $shipment->status_label,
            'status_color' => $shipment->status === 'completed' ? 'green' : 'blue',
            'items_count' => (int) ($shipment->items_count ?? 0),
            'organization' => $this->organization($shipment),
            'warehouse' => $this->warehouse($shipment),
            'entity' => CrmEntityMap::describe($shipment, $viewer),
            'attachments_count' => 0,
            'can' => ['update' => false, 'delete' => false],
        ];
    }

    /**
     * Наша организация документа — юрлицо, на которое 1С его провела.
     *
     * Заглушку (`is_stub`) отдаём вместе с флагом, а не прячем: у неё вместо
     * названия лежит UUID, и менеджер должен видеть, что юрлицо ещё не заведено,
     * а не гадать над строкой из тридцати шести символов.
     *
     * Флаг `ORGANIZATIONS_ENABLED` гейтит показ, поэтому при выключенном флаге
     * поля не уезжают на фронт вовсе.
     *
     * @param  Order|Shipment  $document
     * @return array{name: string, is_stub: bool}|null
     */
    private function organization(Model $document): ?array
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $organization = $document->getRelationValue('organization');

        if (! $organization instanceof Model) {
            return null;
        }

        return [
            'name' => (string) $organization->getAttribute('name'),
            'is_stub' => (bool) $organization->getAttribute('is_stub'),
        ];
    }

    /**
     * Склад отгрузки документа — определяет его 1С, сайт только показывает.
     *
     * @param  Order|Shipment  $document
     */
    private function warehouse(Model $document): ?string
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $warehouse = $document->getRelationValue('warehouse');

        return $warehouse instanceof Model
            ? (string) $warehouse->getAttribute('name')
            : null;
    }

    /**
     * Сумма документа с валютой — форматируется на сервере, как и даты.
     */
    private function money(float $amount, ?string $currencyCode): string
    {
        $symbol = match ($currencyCode) {
            'RUB', null => '₽',
            'KZT' => '₸',
            'BYN' => 'Br',
            default => $currencyCode,
        };

        return number_format($amount, 2, ',', ' ').' '.$symbol;
    }

    /**
     * Запись, исчезнувшая между выборкой ключей и догрузкой моделей.
     *
     * Показываем заглушку, а не выбрасываем: пропуск сломал бы счётчик страницы
     * и увёл бы одну запись из ленты без следа.
     *
     * @return array<string, mixed>
     */
    private function missingEntry(string $type, int $id): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'happened_at' => null,
            'happened_at_label' => null,
            'author' => null,
            'title' => 'Запись удалена',
            'excerpt' => null,
            'body' => null,
            'entity' => null,
            'attachments_count' => 0,
            'can' => ['update' => false, 'delete' => false],
        ];
    }

    /**
     * Письмо в общей форме записи ленты.
     *
     * Тело письма в ленту не отдаём — только тему: HTML целиком раздувал бы страницу
     * и требовал бы санитайзинга на каждой записи. Открыть письмо можно в журнале.
     *
     * @return array<string, mixed>
     */
    public function emailEntry(CrmEmail $email, User $viewer): array
    {
        return [
            'type' => self::TYPE_EMAIL,
            'id' => (int) $email->getKey(),
            'happened_at' => $email->created_at?->toIso8601String(),
            'happened_at_label' => $email->created_at?->format('d.m.Y H:i'),
            'author' => [
                'id' => (int) $email->user_id,
                'name' => $email->author->name,
            ],
            'title' => $email->subject,
            'excerpt' => 'Кому: '.implode(', ', $email->to),
            'entity' => $email->related instanceof Model
                ? CrmEntityMap::describe($email->related, $viewer)
                : null,
            'email' => $this->emails->payload($email, $viewer),
            'can' => [
                'update' => $viewer->can('update', $email),
                'delete' => $viewer->can('delete', $email),
            ],
        ];
    }

    /**
     * Задача в общей форме записи ленты.
     *
     * @return array<string, mixed>
     */
    public function taskEntry(CrmTask $task, User $viewer): array
    {
        return [
            'type' => self::TYPE_TASK,
            'id' => (int) $task->getKey(),
            'happened_at' => $task->created_at?->toIso8601String(),
            'happened_at_label' => $task->created_at?->format('d.m.Y H:i'),
            'author' => [
                'id' => (int) $task->author_id,
                'name' => $task->author->name,
            ],
            'title' => $task->title,
            'excerpt' => $task->description === null ? null : Str::limit($task->description, 300),
            'entity' => $task->related instanceof Model
                ? CrmEntityMap::describe($task->related, $viewer)
                : null,
            'task' => $this->tasks->payload($task, $viewer),
            'can' => [
                'update' => $viewer->can('update', $task),
                'delete' => $viewer->can('delete', $task),
            ],
        ];
    }

    /**
     * Комментарии, оставленные на одной конкретной сущности (врезка в её карточку).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forEntity(Model $entity, User $viewer, int $perPage = 30): LengthAwarePaginator
    {
        $paginator = CrmComment::query()
            ->where('commentable_type', $entity::class)
            ->where('commentable_id', $entity->getKey())
            ->with(['author:id,name', 'commentable'])
            ->withCount($this->attachmentsCount())
            ->chronological()
            ->paginate($perPage);

        return $paginator->through(fn (CrmComment $comment) => $this->commentEntry($comment, $viewer));
    }

    /**
     * Подсчёт вложений одним запросом на страницу.
     *
     * Без него лента не показывала бы, что к записи приложены файлы, а узнать это
     * можно было бы только открыв её — то есть двадцатью запросами на экран.
     *
     * @return array<string, \Closure>
     */
    private function attachmentsCount(): array
    {
        return [
            'media as attachments_count' => fn ($query) => $query->where(
                'collection_name',
                CrmAttachments::COLLECTION,
            ),
        ];
    }

    /**
     * Одна запись ленты в общей форме.
     *
     * @return array<string, mixed>
     */
    public function commentEntry(CrmComment $comment, User $viewer): array
    {
        $entity = $comment->commentable;

        return [
            'type' => self::TYPE_COMMENT,
            'id' => (int) $comment->getKey(),
            'happened_at' => $comment->created_at?->toIso8601String(),
            'happened_at_label' => $comment->created_at?->format('d.m.Y H:i'),
            'edited' => $comment->updated_at !== null
                && $comment->created_at !== null
                && $comment->updated_at->gt($comment->created_at),
            // Автор всегда есть: user_id висит на cascadeOnDelete, а users
            // не мягко удаляются — комментарий уходит вместе с сотрудником.
            'author' => [
                'id' => (int) $comment->user_id,
                'name' => $comment->author->name,
            ],
            'title' => null,
            'excerpt' => Str::limit($comment->body, 300),
            'body' => $comment->body,
            'is_pinned' => (bool) $comment->is_pinned,
            // В списках счётчик приходит из withCount(); на одиночных путях
            // (создание, правка) его нет — там дешевле досчитать, чем показать 0
            // и потерять скрепку у записи с файлами.
            'attachments_count' => (int) ($comment->attachments_count
                ?? $comment->media()->where('collection_name', CrmAttachments::COLLECTION)->count()),
            // Сущность может быть удалена (мягко) — тогда запись остаётся в ленте
            // без ссылки, а не исчезает вместе с документом.
            'entity' => $entity instanceof Model
                ? CrmEntityMap::describe($entity, $viewer)
                : null,
            'can' => [
                'update' => $viewer->can('update', $comment),
                'delete' => $viewer->can('delete', $comment),
            ],
        ];
    }
}
