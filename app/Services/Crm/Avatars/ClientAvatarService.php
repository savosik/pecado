<?php

namespace App\Services\Crm\Avatars;

use App\Models\CrmClientAvatar;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Хранение аватарок партнёров: единственное место, где файл появляется,
 * заменяется и удаляется.
 *
 * Диск приватный (`crm-avatars`), публичного адреса у файла нет вовсе —
 * наружу он выходит только через маршрут CRM под правом crm-clients.view.
 * Отсюда же берётся ETag: контрольная сумма считается при записи, а не при
 * каждой отдаче, иначе список из 25 аватарок читал бы 25 файлов ради хеша.
 */
class ClientAvatarService
{
    public function __construct(private readonly AvatarImageProcessor $processor) {}

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('crm_avatars.disk', 'crm-avatars'));
    }

    /**
     * Загрузка менеджером. Возвращает запись с уже сохранённым файлом.
     */
    public function storeUploaded(User $client, UploadedFile $file, User $actor): CrmClientAvatar
    {
        $webp = $this->processor->toSquareWebp((string) file_get_contents($file->getRealPath()));

        return $this->put($client, $webp, [
            'source' => CrmClientAvatar::SOURCE_MANUAL,
            'uploaded_by' => $actor->getKey(),
            // Ручная аватарка отменяет историю неудач ИИ: партнёр «закрыт»,
            // и ночной пачке он больше не интересен.
            'prompt' => null,
            'image_model' => null,
            'text_model' => null,
        ]);
    }

    /**
     * Результат генерации.
     *
     * @param  array{prompt: string, image_model: string, text_model: string}  $meta
     */
    public function storeGenerated(User $client, string $binary, array $meta): CrmClientAvatar
    {
        $webp = $this->processor->toSquareWebp($binary);

        return $this->put($client, $webp, [
            'source' => CrmClientAvatar::SOURCE_AI,
            'uploaded_by' => null,
            'prompt' => $meta['prompt'],
            'image_model' => $meta['image_model'],
            'text_model' => $meta['text_model'],
        ]);
    }

    /**
     * Записать неудачу: попытка сожгла деньги и ничего не дала.
     *
     * Счётчик и время нужны ночной пачке, чтобы не ломиться к платному API
     * по кругу за одним и тем же партнёром.
     */
    public function markFailed(User $client, string $reason): CrmClientAvatar
    {
        $avatar = $this->recordFor($client);

        $avatar->attempts = $avatar->attempts + 1;
        $avatar->failed_at = now();
        $avatar->failure_reason = Str::limit($reason, 250);
        $avatar->save();

        return $avatar;
    }

    /**
     * Снять аватарку: файл удаляется, запись остаётся с обнулёнными попытками —
     * значит партнёр снова попадёт в ночную пачку и получит новую.
     */
    public function forget(User $client): void
    {
        $avatar = CrmClientAvatar::query()->where('user_id', $client->getKey())->first();

        if ($avatar === null) {
            return;
        }

        $this->deleteFile($avatar);

        $avatar->fill([
            'path' => null,
            'disk' => null,
            'source' => null,
            'size' => null,
            'mime' => null,
            'checksum' => null,
            'prompt' => null,
            'image_model' => null,
            'text_model' => null,
            'uploaded_by' => null,
            'generated_at' => null,
            'attempts' => 0,
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();
    }

    /**
     * Содержимое файла для отдачи маршрутом. NULL — файла нет (или он пропал
     * с диска, что для аватарки не повод падать пятисоткой).
     */
    public function contents(CrmClientAvatar $avatar): ?string
    {
        if ($avatar->path === null) {
            return null;
        }

        $disk = Storage::disk($avatar->disk ?: (string) config('crm_avatars.disk', 'crm-avatars'));

        if (! $disk->exists($avatar->path)) {
            return null;
        }

        return (string) $disk->get($avatar->path);
    }

    /**
     * Запись партнёра — создаётся при первом обращении.
     */
    public function recordFor(User $client): CrmClientAvatar
    {
        return CrmClientAvatar::query()->firstOrCreate(
            ['user_id' => $client->getKey()],
            ['attempts' => 0],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function put(User $client, string $webp, array $meta): CrmClientAvatar
    {
        return DB::transaction(function () use ($client, $webp, $meta): CrmClientAvatar {
            $avatar = $this->recordFor($client);
            $previous = $avatar->path;
            $previousDisk = $avatar->disk;

            // Имя со случайным хвостом, а не «{id}.webp»: браузер, единожды
            // закешировавший аватарку по адресу, при замене показывал бы старую.
            $path = $client->getKey().'/'.Str::random(24).'.webp';

            // Результат проверяем явно: у диска throw выключен, и молча
            // не записанный файл превратился бы в запись «аватарка есть»
            // с битой ссылкой — ровно то, что случилось при первом боевом
            // прогоне, когда деплой стирал каталог из-под ног.
            if ($this->disk()->put($path, $webp) === false) {
                throw new RuntimeException('Не удалось записать файл аватарки на диск.');
            }

            $avatar->fill($meta + [
                'path' => $path,
                'disk' => (string) config('crm_avatars.disk', 'crm-avatars'),
                'size' => strlen($webp),
                'mime' => 'image/webp',
                'checksum' => hash('sha256', $webp),
                'generated_at' => now(),
                'attempts' => 0,
                'failed_at' => null,
                'failure_reason' => null,
            ])->save();

            // Старый файл убираем только после успешной записи нового: иначе
            // сбой на середине оставил бы партнёра вовсе без картинки.
            if ($previous !== null && $previous !== $path) {
                Storage::disk($previousDisk ?: (string) config('crm_avatars.disk', 'crm-avatars'))->delete($previous);
            }

            return $avatar;
        });
    }

    private function deleteFile(CrmClientAvatar $avatar): void
    {
        if ($avatar->path === null) {
            return;
        }

        Storage::disk($avatar->disk ?: (string) config('crm_avatars.disk', 'crm-avatars'))->delete($avatar->path);
    }
}
