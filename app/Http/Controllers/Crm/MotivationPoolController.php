<?php

namespace App\Http\Controllers\Crm;

use App\Models\Motivation\MotivationPoolPackage;
use App\Services\Motivation\PoolListService;
use App\Services\Motivation\PoolPackageService;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Пул и раздача — экран руководителя (mot-35; форма B4).
 *
 * Состояние Пула, кран по каждому работнику, выданные пакеты и их сроки,
 * формирование нового пакета из общего списка с историей покупок сверху.
 */
class MotivationPoolController extends CrmController
{
    public function __construct(
        private readonly PoolPackageService $packages,
        private readonly PoolListService $pool,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/PoolAdmin', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manager_id' => ['required', 'integer', 'exists:personal_managers,id'],
            'partner_ids' => ['required', 'array', 'min:1'],
            'partner_ids.*' => ['integer', 'exists:users,id'],
            'comment' => ['nullable', 'string', 'max:500'],
        ], [
            'manager_id.required' => 'Не указан работник.',
            'partner_ids.required' => 'Выберите партнёров для пакета.',
        ]);

        try {
            $package = $this->packages->issue((int) $data['manager_id'], (array) $data['partner_ids'], $this->crmActor($request), $data['comment'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => sprintf('Пакет № %d выдан: %d партнёров.', $package->getKey(), $package->items()->count())] + $this->payload($request));
    }

    public function refresh(Request $request, MotivationPoolPackage $package): JsonResponse
    {
        $this->packages->refresh($package);

        return response()->json(['ok' => true] + $this->payload($request));
    }

    public function returnToPool(Request $request, MotivationPoolPackage $package): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
        ], ['item_ids.required' => 'Выберите партнёров для возврата в пул.']);

        $count = $this->packages->returnToPool($package, array_map('intval', (array) $data['item_ids']), $this->crmActor($request));

        return response()->json(['ok' => true, 'message' => sprintf('Возвращено в пул: %d. Статус Нового у партнёров сохраняется.', $count)] + $this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $month = CarbonImmutable::now()->startOfMonth();
        $actor = $this->crmActor($request);

        $query = $request->only(['history', 'sort', 'direction', 'page', 'search']);
        if (! array_key_exists('history', $query)) {
            $query['history'] = 1;
        }

        $overview = $this->packages->overview($month);

        // Состав Пула у всех один; оценка «даст вам» справочная и считается
        // по ставкам выбранного работника либо первого в списке.
        $managerId = (int) ($request->integer('manager') ?: ($overview['taps'][0]['manager']['id'] ?? 0));

        return $overview + [
            'month_label' => MonthLabel::ru($month),
            'candidates' => $this->pool->list($managerId, $month, $query),
            'query' => $query,
            'can_edit' => $actor->can('crm-motivation.edit'),
        ];
    }
}
