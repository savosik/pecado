<?php

namespace App\Services\Content;

use App\Enums\InstructionAudience;
use App\Models\Instruction;
use App\Support\VideoEmbed;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Представление инструкции для экранов — одно на кабинет, CRM, WMS и админку.
 *
 * Карточка списка и полная страница различаются только тяжёлой частью:
 * текст блоками, адрес PDF или ролика. Файлы отдаются адресом хранилища
 * (MinIO/S3 либо public-диск), а не потоком через контроллер: PDF в iframe
 * и перемотка видео требуют range-запросов, которых поток не даёт.
 */
class InstructionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function card(Instruction $i, string $url): array
    {
        return [
            'id' => $i->id,
            'title' => $i->title,
            'short_description' => $i->short_description,
            'type' => $i->type->value,
            'type_label' => $i->type->label(),
            'type_color' => $i->type->color(),
            'cover' => $i->getFirstMediaUrl(Instruction::COLLECTION_COVER) ?: null,
            'created_at' => $i->created_at?->toIso8601String(),
            'updated_at' => $i->updated_at?->toIso8601String(),
            'was_updated' => $i->wasUpdated(),
            'url' => $url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Instruction $i, string $url): array
    {
        $file = $i->getFirstMedia(Instruction::COLLECTION_FILE);
        $video = $i->getFirstMedia(Instruction::COLLECTION_VIDEO);

        return [
            ...$this->card($i, $url),
            'content' => $i->type->value === 'text' ? $i->content : null,
            'file' => $file !== null ? $this->file($file) : null,
            'video' => $i->type->value === 'video' ? [
                'embed_url' => VideoEmbed::from($i->video_url),
                'file_url' => $video?->getUrl(),
                'mime_type' => $video?->mime_type,
            ] : null,
        ];
    }

    /**
     * Для админки: то же плюс адресаты и то, что нужно форме.
     *
     * @return array<string, mixed>
     */
    public function admin(Instruction $i): array
    {
        $video = $i->getFirstMedia(Instruction::COLLECTION_VIDEO);

        return [
            ...$this->detail($i, route('admin.instructions.edit', $i)),
            'content' => $i->content,
            'video_url' => $i->video_url,
            'video_file' => $video !== null ? $this->file($video) : null,
            'is_published' => $i->is_published,
            'audiences' => array_map(fn (InstructionAudience $a) => $a->value, $i->audiences()),
            'audience_labels' => array_map(fn (InstructionAudience $a) => [
                'value' => $a->value,
                'label' => $a->label(),
                'color' => $a->color(),
            ], $i->audiences()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function file(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->getUrl(),
            'name' => $media->file_name,
            'size' => $media->size,
            'mime_type' => $media->mime_type,
        ];
    }
}
