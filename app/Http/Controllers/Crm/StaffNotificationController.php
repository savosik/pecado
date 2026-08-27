<?php

namespace App\Http\Controllers\Crm;

use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Notifications\StaffNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Что получает сотрудник.
 *
 * Две поверхности над одной настройкой — как у партнёра: «Мои уведомления»
 * для себя и та же матрица в карточке сотрудника для руководителя. Чужую
 * правит только тот, у кого есть право на команду.
 */
class StaffNotificationController extends CrmController
{
    public function __construct(private readonly StaffNotifications $staff) {}

    public function mine(Request $request): Response
    {
        return Inertia::render('Crm/Pages/MyNotifications/Index', [
            'matrix' => $this->staff->matrixFor($this->crmActor($request)),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->staff->matrixFor($this->resolve($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $target = $this->resolve($request);

        $validated = $request->validate([
            'occasion_key' => ['required', 'string', Rule::in(array_keys($this->staff->all()))],
            'is_enabled' => ['required', 'boolean'],
        ], [
            'occasion_key.in' => 'Неизвестный тип уведомления.',
        ]);

        $this->staff->save($target, $validated['occasion_key'], (bool) $validated['is_enabled'], $actor);

        return response()->json($this->staff->matrixFor($target));
    }

    /**
     * Чьи настройки правим: свои или сотрудника из «Команды».
     *
     * Чужие — только с правом на команду. Без него параметр просто
     * игнорируется, и человек настраивает себя: так надёжнее, чем
     * полагаться на то, что интерфейс не пришлёт лишнего.
     */
    private function resolve(Request $request): User
    {
        $actor = $this->crmActor($request);
        $managerId = $request->integer('manager');

        if ($managerId === 0 || ! $actor->can('crm-team.edit')) {
            return $actor;
        }

        $account = PersonalManager::query()->whereKey($managerId)->value('user_id');

        abort_if($account === null, 404, 'У этого сотрудника нет учётной записи в CRM.');

        return User::query()->findOrFail($account);
    }
}
