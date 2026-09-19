<?php

namespace App\Services\Assistant;

use App\Models\ChatAttachment;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\Assistant\Gateway\AssistantGateway;
use App\Services\Assistant\Gateway\GatewayException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Вложения клиента: приём файла, определение вида по содержимому, передача
 * в Files API Anthropic и превращение в блоки запроса.
 *
 * В `chat_messages.content` файл лежит заглушкой `{type: x-attachment, id}`,
 * а в запрос уходит настоящим блоком (image/document по file_id или
 * container_upload для таблиц): база не раздувается base64, а история
 * остаётся байт-в-байт той же при каждом повторе.
 */
final class AttachmentService
{
    public function __construct(private readonly AssistantGateway $gateway) {}

    /**
     * Вид по MIME из конфига; null — формат не разрешён.
     */
    public static function kindFor(string $mime): ?string
    {
        $kind = config('assistant.attachments.mimes.'.$mime);

        return is_string($kind) ? $kind : null;
    }

    /** @return list<string> */
    public static function allowedMimes(): array
    {
        return array_keys((array) config('assistant.attachments.mimes', []));
    }

    /**
     * MIME по содержимому файла, а не по имени: переименованный .xlsx в .png
     * остаётся таблицей, а нераспознанное содержимое — octet-stream, который
     * в разрешённых форматах не значится. Имени из браузера не верим.
     */
    public static function detectMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $guessed = $path ? \Symfony\Component\Mime\MimeTypes::getDefault()->guessMimeType($path) : null;

        return is_string($guessed) && $guessed !== '' ? $guessed : 'application/octet-stream';
    }

    public function store(ChatThread $thread, User $user, UploadedFile $file): ChatAttachment
    {
        $mime = self::detectMime($file);
        $kind = self::kindFor($mime) ?? ChatAttachment::KIND_TEXT;
        $disk = (string) config('assistant.attachments.disk', 's3');
        $directory = trim((string) config('assistant.attachments.directory', 'assistant'), '/');
        $name = Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'bin');
        $path = Storage::disk($disk)->putFileAs($directory.'/'.$thread->id, $file, $name);

        return ChatAttachment::create([
            'thread_id' => $thread->id,
            'user_id' => $user->getKey(),
            'disk' => $disk,
            'path' => (string) $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'kind' => $kind,
        ]);
    }

    /**
     * Передать в Files API те вложения треда, у которых ещё нет file_id.
     * Ошибки шлюза пробрасываются: без файла ход не имеет смысла.
     *
     * @throws GatewayException
     */
    public function syncToAnthropic(ChatThread $thread): void
    {
        $pending = $thread->attachments()->whereNull('anthropic_file_id')->whereNotNull('message_id')->get();

        foreach ($pending as $attachment) {
            $temp = tempnam(sys_get_temp_dir(), 'assist-');

            if ($temp === false) {
                throw new GatewayException(GatewayException::UNKNOWN, 'Не удалось создать временный файл для вложения.');
            }

            try {
                $stream = Storage::disk($attachment->disk)->readStream($attachment->path);

                if ($stream === null) {
                    throw new GatewayException(GatewayException::INVALID, "Вложение «{$attachment->original_name}» не найдено в хранилище.");
                }

                file_put_contents($temp, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $fileId = $this->gateway->uploadFile($temp, $attachment->original_name, $attachment->mime);
                $attachment->forceFill(['anthropic_file_id' => $fileId])->save();
            } finally {
                @unlink($temp);
            }
        }
    }

    /**
     * Заглушка хода → блок запроса.
     *
     * @return array<string, mixed>
     */
    public static function block(ChatAttachment $attachment): array
    {
        $fileId = $attachment->anthropic_file_id;

        if ($fileId === null) {
            return ['type' => 'text', 'text' => '[Файл «'.$attachment->original_name.'» не удалось передать]'];
        }

        return match ($attachment->kind) {
            ChatAttachment::KIND_IMAGE => ['type' => 'image', 'source' => ['type' => 'file', 'file_id' => $fileId]],
            ChatAttachment::KIND_DOCUMENT => ['type' => 'document', 'source' => ['type' => 'file', 'file_id' => $fileId], 'title' => $attachment->original_name],
            default => ['type' => 'container_upload', 'file_id' => $fileId],
        };
    }

    public static function placeholder(ChatAttachment $attachment): array
    {
        return ['type' => 'x-attachment', 'id' => $attachment->id];
    }
}
