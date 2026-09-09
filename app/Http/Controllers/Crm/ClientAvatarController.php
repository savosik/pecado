<?php

namespace App\Http\Controllers\Crm;

use App\Jobs\GenerateClientAvatar;
use App\Models\CrmClientAvatar;
use App\Models\User;
use App\Services\Crm\Avatars\ClientAvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Аватарки партнёров: отдача, загрузка менеджером, перерисовка и снятие.
 *
 * Файл выходит наружу только отсюда — под `crm-clients.view` и через тот же
 * скоуп видимости, что список: чужой партнёр отвечает 404, а не 403, иначе
 * ответ подтверждал бы, что такой партнёр существует.
 *
 * Партнёр свою аватарку не видит: в кабинете этого маршрута нет, а на диске
 * файлы лежат приватно. Это не мелочь — рисунок делает отдел продаж для себя.
 */
class ClientAvatarController extends CrmController
{
    public function __construct(private readonly ClientAvatarService $avatars) {}

    /**
     * Картинка. Кешируется браузером по ETag: список из 25 строк не должен
     * тянуть 25 файлов при каждом переходе по страницам.
     */
    public function show(Request $request, int $client): SymfonyResponse
    {
        $user = $this->resolveClient($request, $client);

        $avatar = CrmClientAvatar::query()->where('user_id', $user->getKey())->first();
        $contents = $avatar === null ? null : $this->avatars->contents($avatar);

        if ($avatar === null || $contents === null) {
            // Аватарки нет — это штатное состояние (её ещё не нарисовали),
            // поэтому 404 без тела: фронт покажет инициалы.
            return response()->noContent(SymfonyResponse::HTTP_NOT_FOUND);
        }

        $etag = '"'.($avatar->checksum ?: md5($contents)).'"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->noContent(SymfonyResponse::HTTP_NOT_MODIFIED)->setEtag($etag, true);
        }

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => $avatar->mime ?: 'image/webp',
            'Content-Length' => (string) strlen($contents),
            // private: аватарка не должна осесть в общем кеше прокси —
            // она видна только сотрудникам.
            'Cache-Control' => 'private, max-age=604800',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Загрузка менеджером. Право то же, что на правку профиля партнёра:
     * аватарка — часть карточки, а не отдельный раздел.
     */
    public function store(Request $request, int $client): JsonResponse
    {
        $user = $this->resolveClient($request, $client);
        $actor = $this->crmActor($request);

        abort_unless($actor->can('crm-profile.edit'), SymfonyResponse::HTTP_FORBIDDEN);

        $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:'.(int) config('crm_avatars.max_upload_kb', 5120),
            ],
        ], [
            'avatar.required' => 'Выберите файл с картинкой.',
            'avatar.image' => 'Файл должен быть картинкой.',
            'avatar.mimes' => 'Подойдёт JPEG, PNG или WebP.',
            'avatar.max' => 'Картинка слишком тяжёлая — не больше :max КБ.',
        ]);

        $avatar = $this->avatars->storeUploaded($user, $request->file('avatar'), $actor);

        return response()->json($this->payload($user, $avatar));
    }

    /**
     * Перерисовать: ставит задание в очередь и сразу отвечает — рисование
     * занимает десятки секунд, держать ради него запрос незачем.
     */
    public function regenerate(Request $request, int $client): JsonResponse
    {
        $user = $this->resolveClient($request, $client);

        abort_unless($this->crmActor($request)->can('crm-profile.edit'), SymfonyResponse::HTTP_FORBIDDEN);

        if (! config('crm_avatars.generation.enabled')) {
            return response()->json(['message' => 'Генерация аватарок выключена.'], SymfonyResponse::HTTP_CONFLICT);
        }

        GenerateClientAvatar::dispatch((int) $user->getKey(), true);

        return response()->json(['message' => 'Рисуем — картинка появится через минуту.']);
    }

    /**
     * Снять аватарку. Файл удаляется, счётчик попыток обнуляется — партнёр
     * снова попадёт в ночную пачку и получит новую.
     */
    public function destroy(Request $request, int $client): JsonResponse
    {
        $user = $this->resolveClient($request, $client);

        abort_unless($this->crmActor($request)->can('crm-profile.edit'), SymfonyResponse::HTTP_FORBIDDEN);

        $this->avatars->forget($user);

        return response()->json(['message' => 'Аватарка снята.']);
    }

    /**
     * Тот же резолв, что в карточке: чужой партнёр — 404.
     */
    private function resolveClient(Request $request, int $client): User
    {
        return User::query()
            ->visibleInCrm($this->crmActor($request))
            ->findOrFail($client);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, CrmClientAvatar $avatar): array
    {
        return [
            'has_avatar' => $avatar->hasFile(),
            'source' => $avatar->source,
            // Версия в адресе — чтобы браузер забрал новый файл сразу после
            // замены, а не через неделю, когда истечёт его кеш.
            'url' => $avatar->hasFile()
                ? route('crm.clients.avatar', ['client' => $user->getKey(), 'v' => substr((string) $avatar->checksum, 0, 12)])
                : null,
        ];
    }
}
