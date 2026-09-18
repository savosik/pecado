<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\User\PickupController as CabinetPickupController;
use App\Models\Pickup\PickupHandover;
use App\Models\WmsAccessLink;
use App\Services\Pickup\PickupQueue;
use App\Services\Wms\AccessLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Ссылки для кладовщиков» (pick-17): выпустить, показать QR, перевыпустить, отключить.
 * Право `wms-access.edit` — только у начальника склада.
 */
class AccessLinkController extends WmsController
{
    public function __construct(private readonly AccessLinkService $links, private readonly PickupQueue $queue) {}

    public function index(): Response
    {
        return Inertia::render('Wms/Pages/AccessLinks/Index', ['links' => $this->rows()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']], [], ['name' => 'название']);

        [$link] = $this->links->create($data['name'], $this->wmsActor($request));

        return response()->json(['ok' => true, 'message' => 'Ссылка выпущена. Перешлите её кладовщику.', 'links' => $this->rows()]);
    }

    public function regenerate(WmsAccessLink $link): JsonResponse
    {
        $this->links->regenerate($link);

        return response()->json(['ok' => true, 'message' => 'Ссылка перевыпущена: старая больше не входит, телефоны с ней выйдут сами.', 'links' => $this->rows()]);
    }

    /**
     * Журнал выдач по ссылке: кто, когда, что и кому выдал с этого телефона. Ответственность
     * начальнику склада видна по каждой ссылке отдельно.
     */
    public function handovers(Request $request, WmsAccessLink $link): JsonResponse
    {
        $days = max(1, min(90, (int) $request->query('days', 30)));

        $handovers = PickupHandover::query()
            ->where('issued_by', $link->user_id)
            ->where('issued_at', '>=', now()->subDays($days))
            ->with(['goodsIssue', 'canceller:id,name', 'pass:id,code'])
            ->orderByDesc('issued_at')
            ->limit(500)
            ->get();

        $rows = $this->queue->present($handovers->pluck('goodsIssue')->filter()->unique('id')->values())->keyBy('id');

        return response()->json(['days' => $days, 'rows' => $handovers->map(fn (PickupHandover $h) => [
            'id' => $h->id,
            'issued_at' => $h->issued_at->toIso8601String(),
            'client' => $rows->get($h->goods_issue_id)['client'] ?? 'Клиент не определён',
            'company' => $rows->get($h->goods_issue_id)['company'] ?? null,
            'orders' => collect($rows->get($h->goods_issue_id)['orders'] ?? [])->pluck('number')->all(),
            'goods_issue' => $h->goodsIssue?->number,
            'packages_count' => $h->packages_count,
            'recipient_name' => $h->recipient_name,
            'method_label' => $h->method_label,
            'pass_code' => $h->pass?->code,
            'box_verified' => $h->box_verified,
            'is_cancelled' => $h->cancelled_at !== null,
            'cancelled_by' => $h->canceller?->name,
            'cancel_reason' => $h->cancel_reason,
            'needs_review' => $h->needs_review,
        ])->values()->all()]);
    }

    public function revoke(WmsAccessLink $link): JsonResponse
    {
        $this->links->revoke($link);

        return response()->json(['ok' => true, 'message' => 'Ссылка отключена.', 'links' => $this->rows()]);
    }

    /**
     * Загрузка по ссылкам: сколько выдач сегодня, за 7 и 30 дней, всего, отмен, когда последняя,
     * и по часам за 30 дней — начальник видит, когда стойка работает, а когда простаивает.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    private function stats(\Illuminate\Support\Collection $userIds): array
    {
        if ($userIds->isEmpty()) {
            return [];
        }

        $tz = config('warehouse.timezone', 'Europe/Moscow');
        $rows = PickupHandover::query()
            ->whereIn('issued_by', $userIds)
            ->get(['issued_by', 'issued_at', 'cancelled_at']);

        $today = now($tz)->startOfDay();
        $week = now($tz)->subDays(7);
        $month = now($tz)->subDays(30);

        return $rows->groupBy('issued_by')->map(function ($group) use ($today, $week, $month, $tz) {
            $active = $group->whereNull('cancelled_at');
            $hours = array_fill(9, 12, 0);
            foreach ($active->where('issued_at', '>=', $month) as $h) {
                $hour = (int) $h->issued_at->timezone($tz)->format('G');
                if (isset($hours[$hour])) {
                    $hours[$hour]++;
                }
            }

            return [
                'today' => $active->where('issued_at', '>=', $today)->count(),
                'week' => $active->where('issued_at', '>=', $week)->count(),
                'month' => $active->where('issued_at', '>=', $month)->count(),
                'total' => $active->count(),
                'cancelled' => $group->whereNotNull('cancelled_at')->count(),
                'last_at' => $group->max('issued_at')?->toIso8601String(),
                'hours' => $hours,
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        $links = WmsAccessLink::query()->with(['user:id,name', 'creator:id,name'])->orderByDesc('id')->get();
        $stats = $this->stats($links->pluck('user_id'));

        return $links
            ->map(function (WmsAccessLink $link) use ($stats) {
                $url = $link->isActive() ? $this->links->url($link) : null;

                return [
                    'id' => $link->id,
                    'name' => $link->name,
                    'account' => $link->user?->name,
                    'url' => $url,
                    'qr' => $url ? CabinetPickupController::qrDataUri($url) : null,
                    'is_active' => $link->isActive(),
                    'uses_count' => $link->uses_count,
                    'last_used_at' => $link->last_used_at?->format('d.m.Y H:i'),
                    'rotated_at' => $link->rotated_at?->format('d.m.Y H:i'),
                    'created_at' => $link->created_at?->format('d.m.Y H:i'),
                    'created_by' => $link->creator?->name,
                    'stats' => $stats[$link->user_id] ?? ['today' => 0, 'week' => 0, 'month' => 0, 'total' => 0, 'cancelled' => 0, 'last_at' => null, 'hours' => []],
                ];
            })->values()->all();
    }
}
