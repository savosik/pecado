<?php

namespace App\Http\Controllers\Crm;

use App\Enums\DebtLevel;
use App\Http\Requests\Crm\StoreDebtPauseRequest;
use App\Models\DebtPause;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Debt\DebtOverview;
use App\Services\Debt\DebtStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Дебиторка — рабочий список отдела, не настройки (карточка debt-05).
 *
 * Пороги живут в config/debt.php; здесь только партнёры со ступенью,
 * «почему» одной строкой и единственная ручка — разблокировка до даты.
 */
class DebtController extends CrmController
{
    public function index(Request $request, DebtOverview $overview): Response
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesDepartment($request);
        $level = (string) $request->input('level', '');
        $managerId = $seesAll && $request->filled('manager_id') ? (int) $request->input('manager_id') : null;

        $clients = User::query()->visibleInCrm($actor);

        return Inertia::render('Crm/Pages/Debt/Index', [
            ...$overview->rows($clients, $level !== '' ? $level : null, $managerId),
            'filters' => [
                'level' => $level,
                'manager_id' => $managerId,
            ],
            'levels' => DebtLevel::options(),
            'managers' => $seesAll ? PersonalManager::query()->active()->orderBy('name')->get(['id', 'name']) : [],
            'seesAll' => $seesAll,
            'pauseMaxDays' => $actor->can('crm-clients-all.view')
                ? (int) config('debt.pause_max_days_head', 30)
                : (int) config('debt.pause_max_days_manager', 14),
            'thresholds' => [
                'min_overdue' => (float) config('debt.min_overdue'),
                'grace_bank_days' => (int) config('debt.grace_bank_days'),
                'no_preorders_days' => (int) config('debt.no_preorders_days'),
                'no_orders_days' => (int) config('debt.no_orders_days'),
                'hold_days' => (int) config('debt.hold_days'),
                'hold_share' => (float) config('debt.hold_share'),
            ],
        ]);
    }

    public function storePause(StoreDebtPauseRequest $request, DebtStateService $states): RedirectResponse
    {
        $actor = $this->crmActor($request);

        // Чужой партнёр — 404, как везде в CRM.
        $client = User::query()->visibleInCrm($actor)->findOrFail((int) $request->validated('user_id'));
        $companyId = $request->validated('company_id');

        if ($companyId !== null && ! $client->companies()->whereKey($companyId)->exists()) {
            return back()->withErrors(['company_id' => 'Контрагент не принадлежит этому партнёру.']);
        }

        DebtPause::create([
            'user_id' => $client->getKey(),
            'company_id' => $companyId,
            'until' => $request->validated('until'),
            'reason' => $request->validated('reason'),
            'created_by' => $actor->getKey(),
        ]);

        // Ступень остаётся видна, но «почему» должно упоминать разблокировку сразу.
        $states->recalculate(onlyUserIds: [(int) $client->getKey()], upwardOnly: true);

        return back()->with('success', 'Разблокировка поставлена — ограничения сняты до указанной даты.');
    }

    public function releasePause(Request $request, DebtPause $pause): RedirectResponse
    {
        $actor = $this->crmActor($request);
        User::query()->visibleInCrm($actor)->findOrFail($pause->user_id);

        if ($pause->released_at !== null) {
            return back()->with('info', 'Разблокировка уже снята.');
        }

        $pause->forceFill([
            'released_at' => now(),
            'released_reason' => DebtPause::RELEASED_MANUAL,
        ])->save();

        return back()->with('success', 'Разблокировка снята — ограничения по ступени действуют снова.');
    }
}
