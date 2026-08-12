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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Вложения CRM: файлы к партнёру, заказу, реализации и комментарию.
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
            'kind' => ['sometimes', Rule::in(['file', 'voice'])],
        ]);

        $entity = $this->resolver->resolveForActor(
            $actor,
            $validated['entity_type'],
            (int) $validated['entity_id'],
        );

        // Страховка на будущее: в карту сущностей может попасть модель без медиа.
        abort_unless($entity instanceof HasMedia, 404);

        $collection = ($validated['kind'] ?? 'file') === 'voice'
            ? CrmAttachments::VOICE_COLLECTION
            : CrmAttachments::COLLECTION;

        return response()->json(['data' => $this->present($entity, $actor, $collection)]);
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
                $request->collection(),
                $entity,
                // MediaLibrary не хранит автора загрузки, а он нужен для правила
                // «удалить может автор или РОП» — кладём в custom properties.
                [
                    'uploaded_by' => $actor->getKey(),
                    'uploaded_by_name' => $actor->name,
                    // Длительность считает браузер: у сервера её взять неоткуда
                    // без разбора контейнера, а в списке она нужна сразу.
                    'duration_seconds' => $request->integer('duration_seconds') ?: null,
                ],
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

    /**
     * Отдать файл через приложение.
     *
     * Прямая ссылка на диск (`$media->getUrl()`) сюда не годится по двум причинам.
     * Во-первых, это публичный URL без авторизации: документы партнёра утекали бы
     * любому, кто получил ссылку, в обход скоупа `visibleInCrm`. Во-вторых, на dev
     * `AWS_URL` указывает на прод, поэтому URL файла, загруженного на dev, ведёт
     * туда, где этого файла нет.
     */
    public function download(Request $request, Media $media): StreamedResponse
    {
        $this->assertAccessible($this->crmActor($request), $media);

        return $media->toInlineResponse($request);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        $actor = $this->crmActor($request);

        $this->assertAccessible($actor, $media);

        $uploadedBy = $media->getCustomProperty('uploaded_by');
        $canDelete = $actor->can('crm-attachments.delete')
            && ((int) $uploadedBy === (int) $actor->getKey() || $actor->can('crm-department.edit'));

        abort_unless($canDelete, 403);

        $this->media->delete($media);

        return response()->json(['deleted' => true]);
    }

    /**
     * Доступно ли вложение актору. Отказ — 404: чужой файл не должен подтверждать
     * даже своё существование.
     */
    private function assertAccessible(User $actor, Media $media): void
    {
        abort_unless($media->collection_name === CrmAttachments::COLLECTION, 404);

        // Владелец может отсутствовать: строка media переживает жёсткое удаление
        // своей модели, и докблок пакета этого не отражает.
        /** @var \Illuminate\Database\Eloquent\Model|null $owner */
        $owner = $media->model;

        abort_unless($owner !== null && $this->resolver->canAccess($actor, $owner), 404);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function present(HasMedia $entity, User $actor, string $collection = CrmAttachments::COLLECTION): array
    {
        /** @var \Illuminate\Support\Collection<int, Media> $items */
        $items = $entity->getMedia($collection);

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
            'url' => route('crm.attachments.download', $media->getKey()),
            'uploaded_at' => $media->created_at?->format('d.m.Y H:i'),
            'uploaded_by' => $media->getCustomProperty('uploaded_by_name'),
            // Длительность посчитал браузер при записи: у сервера её взять
            // неоткуда без разбора контейнера.
            'duration_seconds' => $media->getCustomProperty('duration_seconds'),
            'duration_label' => $this->durationLabel($media->getCustomProperty('duration_seconds')),
            'can_delete' => $actor->can('crm-attachments.delete')
                && ((int) $uploadedBy === (int) $actor->getKey() || $actor->can('crm-department.edit')),
        ];
    }

    /**
     * «3:07» вместо 187 секунд — плеер показывает время, а не число.
     */
    private function durationLabel(mixed $seconds): ?string
    {
        $value = (int) $seconds;

        if ($value <= 0) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($value, 60), $value % 60);
    }
}
