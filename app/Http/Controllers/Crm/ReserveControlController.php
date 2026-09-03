<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderReserveOverride;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Резервы заказов в CRM (v16.9.0, res-11): сводка злоупотреблений и рычаг РОПа.
 *
 * Дедлайн (res-09) — предохранитель на один заказ; этот экран — контроль
 * привычек партнёра: кто резервирует и бросает. Автоматики намеренно нет —
 * только сигнал (красная зона) и ручное решение РОПа: сократить окно или
 * отключить режим партнёру (order_reserve_overrides, «только отклонения»).
 */
class ReserveControlController extends Controller
{
    /**
     * Сводка по партнёрам-участникам. GET /crm/reserves
     */
    public function index(Request $request): InertiaResponse
    {
        $windowDays = 90;
        $since = now()->subDays($windowDays);

        // Партнёры, у которых режим включён 1С либо были резервы за окно
        $outcomes = Order::withTrashed()
            ->select('user_id', 'reserve_outcome', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('reserve_outcome')
            ->where('updated_at', '>=', $since)
            ->groupBy('user_id', 'reserve_outcome')
            ->get()
            ->groupBy('user_id');

        $active = Order::query()
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->where('reserve', true)
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id');

        $partnerIds = $outcomes->keys()
            ->merge($active->keys())
            ->merge(User::query()->where('reserve_allowed', true)->pluck('id'))
            ->unique()
            ->values();

        $overrides = OrderReserveOverride::query()
            ->whereIn('user_id', $partnerIds)
            ->get()
            ->keyBy('user_id');

        $partners = User::query()
            ->whereIn('id', $partnerIds)
            ->get(['id', 'name', 'erp_name', 'reserve_allowed'])
            ->map(function (User $partner) use ($outcomes, $active, $overrides) {
                $byOutcome = ($outcomes[$partner->id] ?? collect())->pluck('cnt', 'reserve_outcome');
                $confirmed = (int) ($byOutcome['confirmed'] ?? 0);
                $cancelled = (int) ($byOutcome['cancelled'] ?? 0);
                $expired = (int) ($byOutcome['expired'] ?? 0);
                $finished = $confirmed + $cancelled + $expired;
                $override = $overrides[$partner->id] ?? null;

                return [
                    'user_id' => $partner->id,
                    'name' => $partner->erp_name ?: $partner->name,
                    'reserve_allowed' => (bool) $partner->reserve_allowed,
                    'active' => (int) ($active[$partner->id] ?? 0),
                    'confirmed' => $confirmed,
                    'cancelled' => $cancelled,
                    'expired' => $expired,
                    'finished' => $finished,
                    // Доли считаются от завершённых резервов окна
                    'expired_share' => $finished > 0 ? round($expired / $finished, 2) : null,
                    'confirmed_share' => $finished > 0 ? round($confirmed / $finished, 2) : null,
                    'disabled' => (bool) ($override?->disabled ?? false),
                    'hours' => $override?->hours,
                ];
            })
            ->sortByDesc(fn (array $row) => [$row['expired_share'] ?? -1, $row['finished']])
            ->values();

        return Inertia::render('Crm/Pages/Reserves/Index', [
            'partners' => $partners,
            'windowDays' => $windowDays,
            'defaultHours' => (int) config('order_reserve.hours'),
            'alertShare' => (float) config('order_reserve.expired_share_alert'),
            'reserveEnabled' => (bool) config('order_reserve.enabled'),
            'canEdit' => $request->user()->can('crm-reserves.edit'),
        ]);
    }

    /**
     * Рычаг РОПа: отклонение по партнёру. PUT /crm/reserves/{user}
     *
     * Хранятся только отклонения: выключенный флаг и пустой срок = возврат
     * к умолчаниям = удаление строки.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'disabled' => ['required', 'boolean'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ], [
            'hours.min' => 'Срок резерва — от 1 часа.',
            'hours.max' => 'Срок резерва — не больше недели (168 часов).',
        ]);

        $isDeviation = $validated['disabled'] || $validated['hours'] !== null;

        if (! $isDeviation) {
            OrderReserveOverride::query()->where('user_id', $user->id)->delete();

            return back()->with('success', 'Партнёр возвращён к умолчаниям режима.');
        }

        OrderReserveOverride::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'disabled' => $validated['disabled'],
                'hours' => $validated['hours'],
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', $validated['disabled']
            ? 'Резерв для партнёра отключён на сайте.'
            : 'Индивидуальный срок резерва сохранён.');
    }
}
