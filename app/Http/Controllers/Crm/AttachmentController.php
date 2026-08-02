<?php

namespace App\Http\Controllers\Crm;

use App\Http\Requests\Crm\StoreCrmAttachmentRequest;
use App\Models\Media;
use App\Models\User;
use App\Services\Crm\CrmEntityResolver;
use App\Services\Media\MediaService;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

/**
 * Вложения CRM: файлы к клиенту, заказу, реализации и комментарию.
 *
 * Авторизация идёт по владельцу вложения, а не по самому файлу: прикрепить файл к заказу
 * можно ровно тогда, когда этот заказ виден в скоупе. Иначе загрузка стала бы дырой
 * в обход проверок карточки.
 */
class AttachmentController extends CrmController
{
    public function __construct(
        private readonly CrmEntityResolver $resolver,
        private readonly MediaService $media,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(CrmEntityMap::types())],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        $entity = $this->resolver->resolveForActor(
            $actor,
            $validated['entity_type'],
            (int) $validated['entity_id'],
        );

        // Страховка на будущее: в карту сущностей может попасть модель без медиа.
        abort_unless($entity instanceof HasMedia, 404);

        return response()->json(['data' => $this->present($entity, $actor)]);
    }

    public function store(StoreCrmAttachmentRequest $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $entity = $this->resolver->resolveForActor(
            $actor,
            $request->string('entity_type')->value(),
            (int) $request->integer('entity_id'),
        );

        try {
            $media = $this->media->upload(
                $request->file('file'),
                CrmAttachments::COLLECTION,
                $entity,
                // MediaLibrary не хранит автора загрузки, а он нужен для правила
                // «удалить может автор или РОП» — кладём в custom properties.
                ['uploaded_by' => $actor->getKey(), 'uploaded_by_name' => $actor->name],
            );
        } catch (FileUnacceptableForCollection $e) {
            // Валидация проверяет заявленный тип, MediaLibrary — фактическое содержимое.
            // Расхождение (пустой файл, переименованный exe) должно выглядеть как
            // понятная ошибка формы, а не как 500.
            throw ValidationException::withMessages([
                'file' => 'Содержимое файла не соответствует его типу или файл пуст.',
            ]);
        }

        return response()->json($this->presentOne($media, $actor), 201);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        $actor = $this->crmActor($request);

        abort_unless($media->collection_name === CrmAttachments::COLLECTION, 404);

        // Владелец может отсутствовать: строка media переживает жёсткое удаление
        // своей модели, и докблок пакета этого не отражает.
        /** @var \Illuminate\Database\Eloquent\Model|null $owner */
        $owner = $media->model;
        abort_unless($owner !== null && $this->resolver->canAccess($actor, $owner), 404);

        $uploadedBy = $media->getCustomProperty('uploaded_by');
        $canDelete = $actor->can('crm-attachments.delete')
            && ((int) $uploadedBy === (int) $actor->getKey() || $actor->can('crm-clients-all.view'));

        abort_unless($canDelete, 403);

        $this->media->delete($media);

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function present(HasMedia $entity, User $actor): array
    {
        /** @var \Illuminate\Support\Collection<int, Media> $items */
        $items = $entity->getMedia(CrmAttachments::COLLECTION);

        return $items->map(fn (Media $media) => $this->presentOne($media, $actor))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOne(Media $media, User $actor): array
    {
        $uploadedBy = $media->getCustomProperty('uploaded_by');

        return [
            'id' => (int) $media->getKey(),
            'name' => $media->name,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => (int) $media->size,
            'size_label' => $media->human_readable_size,
            'url' => $media->getUrl(),
            'uploaded_at' => $media->created_at?->format('d.m.Y H:i'),
            'uploaded_by' => $media->getCustomProperty('uploaded_by_name'),
            'can_delete' => $actor->can('crm-attachments.delete')
                && ((int) $uploadedBy === (int) $actor->getKey() || $actor->can('crm-clients-all.view')),
        ];
    }
}
