<?php

namespace App\Services\Crm;

use App\Models\CrmComment;
use App\Models\CrmEmail;
use App\Models\CrmTask;
use App\Models\User;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Сквозная лента клиента: всё, что менеджеры оставили по нему и его документам.
 *
 * Источников уже два — комментарии и задачи, письма (crm-10) добавятся третьим.
 * Все они сводятся к одной форме записи, а не заводят по вкладке: карточка клиента
 * должна показывать одну хронологию, а не три ленты с разным поведением.
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

    public function __construct(
        private readonly CrmTaskService $tasks,
        private readonly CrmEmailService $emails,
    ) {}

    /**
     * Лента клиента: комментарии и задачи по нему самому, его заказам и отгрузкам.
     *
     * Источники сливаются UNION-ом по ключам (тип, id, дата), и только страница
     * результата догружается моделями. Альтернатива — вычитать оба источника целиком
     * и слить в памяти — на клиенте с длинной историей означала бы тянуть сотни
     * записей ради двадцати показанных.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forClient(User $client, User $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $clientId = (int) $client->getKey();

        $comments = DB::table('crm_comments')
            ->selectRaw("'".self::TYPE_COMMENT."' as source, id, created_at as happened_at, is_pinned")
            ->where('client_user_id', $clientId)
            ->whereNull('deleted_at');

        $tasks = DB::table('crm_tasks')
            ->selectRaw("'".self::TYPE_TASK."' as source, id, created_at as happened_at, 0 as is_pinned")
            ->where('client_user_id', $clientId)
            ->whereNull('deleted_at');

        // Черновики в ленту не идут: письмо становится событием в жизни клиента,
        // только когда его отправили.
        $emails = DB::table('crm_emails')
            ->selectRaw("'".self::TYPE_EMAIL."' as source, id, created_at as happened_at, 0 as is_pinned")
            ->where('client_user_id', $clientId)
            ->whereIn('status', ['queued', 'sent', 'failed']);

        // Задачи коллег по общему клиенту рядовому менеджеру не показываем — то же
        // правило, что в CrmTaskPolicy: поручения между сотрудниками не публичная лента.
        if (! $viewer->can('crm-clients-all.view')) {
            $tasks->where(fn ($query) => $query
                ->where('author_id', $viewer->getKey())
                ->orWhere('assignee_id', $viewer->getKey()));
        }

        $paginator = DB::query()
            ->fromSub($comments->unionAll($tasks)->unionAll($emails), 'timeline')
            ->orderByDesc('is_pinned')
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->hydrate($paginator, $viewer);
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

        /** @var LengthAwarePaginator<int, array<string, mixed>> $hydrated */
        $hydrated = $paginator->through(function (object $row) use ($comments, $tasks, $emails, $viewer): array {
            $id = (int) $row->id;

            if ($row->source === self::TYPE_COMMENT) {
                $comment = $comments->get($id);

                return $comment instanceof CrmComment
                    ? $this->commentEntry($comment, $viewer)
                    : $this->missingEntry(self::TYPE_COMMENT, $id);
            }

            if ($row->source === self::TYPE_TASK) {
                $task = $tasks->get($id);

                return $task instanceof CrmTask
                    ? $this->taskEntry($task, $viewer)
                    : $this->missingEntry(self::TYPE_TASK, $id);
            }

            $email = $emails->get($id);

            return $email instanceof CrmEmail
                ? $this->emailEntry($email, $viewer)
                : $this->missingEntry(self::TYPE_EMAIL, $id);
        });

        return $hydrated;
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
