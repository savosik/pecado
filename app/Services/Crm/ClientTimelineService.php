<?php

namespace App\Services\Crm;

use App\Models\CrmComment;
use App\Models\User;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Сквозная лента клиента: всё, что менеджеры оставили по нему и его документам.
 *
 * Сейчас источник один — комментарии. Задачи (crm-09) и письма (crm-10) подмешиваются
 * в этот же сервис и в ту же форму записи, а не заводят вторую ленту: карточка клиента
 * должна показывать одну хронологию, а не три вкладки с разным поведением.
 */
class ClientTimelineService
{
    /**
     * Форма записи ленты. Единая для всех типов источников, чтобы фронт рисовал
     * ленту одним компонентом.
     */
    private const TYPE_COMMENT = 'comment';

    /**
     * Лента клиента: комментарии по нему самому, его заказам и отгрузкам.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forClient(User $client, User $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = CrmComment::query()
            ->forClient((int) $client->getKey())
            ->with(['author:id,name', 'commentable'])
            ->chronological()
            ->paginate($perPage);

        return $paginator->through(fn (CrmComment $comment) => $this->commentEntry($comment, $viewer));
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
            ->chronological()
            ->paginate($perPage);

        return $paginator->through(fn (CrmComment $comment) => $this->commentEntry($comment, $viewer));
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
