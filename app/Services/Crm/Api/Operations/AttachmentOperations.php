<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\Media;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\CrmEntityResolver;
use App\Support\Crm\CrmAttachments;
use Spatie\MediaLibrary\HasMedia;

/**
 * Вложения: перечень файлов, прикреплённых к партнёру, заказу или реализации.
 *
 * Только чтение. Загрузка файла остаётся действием браузера: агент оперирует
 * текстом, и «загрузить файл» через него означало бы либо base64 в аргументах
 * MCP-вызова, либо второй канал загрузки мимо тех же проверок — ни то, ни другое
 * не стоит удобства. Ссылка на скачивание отдаётся приложением, а не диском,
 * поэтому доступ к файлу остаётся за скоупом.
 */
class AttachmentOperations
{
    public function __construct(private readonly CrmEntityResolver $resolver) {}

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        $entity = $this->resolver->resolveForActor(
            $actor,
            (string) $input->string('entity_type'),
            (int) $input->int('entity_id'),
        );

        if (! $entity instanceof HasMedia) {
            return ['data' => []];
        }

        $items = $entity->getMedia(CrmAttachments::COLLECTION)
            ->map(fn (Media $media): array => [
                'id' => (int) $media->getKey(),
                'name' => $media->name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => (int) $media->size,
                'url' => route('crm.attachments.download', $media->getKey()),
                'uploaded_at' => $media->created_at?->toIso8601String(),
                'uploaded_by' => $media->getCustomProperty('uploaded_by_name'),
            ])
            ->values()
            ->all();

        return ['data' => $items];
    }
}
