<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmCalendarToken;
use App\Services\Crm\TaskIcsFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Подписной ICS-фид задач и управление его токенами.
 *
 * Сам фид отдаётся БЕЗ сессии — Google и Яндекс ходят по ссылке анонимно,
 * доступ охраняет только токен. Управление токенами — обычные CRM-эндпоинты.
 */
class CalendarFeedController extends Controller
{
    public function __construct(private readonly TaskIcsFeedService $feed) {}

    /**
     * Фид по токену. Права переоцениваются на каждый запрос: фид отдела,
     * выпущенный РОПом, перестаёт отдавать отдел, если право отозвали.
     */
    public function feed(string $token): Response
    {
        /** @var CrmCalendarToken $record */
        $record = CrmCalendarToken::query()
            ->where('token', $token)
            ->with('user')
            ->firstOrFail();

        abort_unless($record->user?->can('crm-tasks.view'), 404);

        // Отметка жизни подписки: в UI видно «календарь забирал фид N часов назад».
        $record->forceFill(['last_fetched_at' => now()])->save();

        return response($this->feed->build($record), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="pecado-tasks.ics"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Текущие ссылки актора (личная и, для РОПа, по отделу) — создаются лениво.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can('crm-tasks.view'), 403);

        return response()->json(['data' => $this->payload($request)]);
    }

    /**
     * Перевыпуск: старая ссылка умирает сразу — способ отозвать утёкший URL.
     */
    public function rotate(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can('crm-tasks.view'), 403);

        $validated = $request->validate([
            'scope' => ['required', 'in:mine,department'],
        ]);

        if ($validated['scope'] === CrmCalendarToken::SCOPE_DEPARTMENT) {
            abort_unless($user->can('crm-department.view'), 403);
        }

        CrmCalendarToken::forUser($user, $validated['scope'])->rotate();

        return response()->json(['data' => $this->payload($request)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payload(Request $request): array
    {
        $user = $request->user();

        $scopes = [CrmCalendarToken::SCOPE_MINE];

        if ($user->can('crm-department.view')) {
            $scopes[] = CrmCalendarToken::SCOPE_DEPARTMENT;
        }

        return array_map(function (string $scope) use ($user): array {
            $token = CrmCalendarToken::forUser($user, $scope);

            return [
                'scope' => $scope,
                'label' => $scope === CrmCalendarToken::SCOPE_DEPARTMENT ? 'Задачи отдела' : 'Мои задачи',
                'url' => url(route('crm.tasks.feed', ['token' => $token->token], false)),
                'last_fetched_at_label' => $token->last_fetched_at?->diffForHumans(),
            ];
        }, $scopes);
    }
}
