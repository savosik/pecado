<?php

namespace App\Http\Controllers\User;

use App\Enums\OrderFulfilmentStage as Stage;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\GoodsIssue;
use App\Models\Pickup\PickupPass;
use App\Services\Pickup\OrderFulfilmentResolver;
use App\Services\Pickup\PickupDeskSchedule;
use App\Services\Pickup\PickupPassException;
use App\Services\Pickup\PickupPassService;
use App\Services\Warehouse\WarehouseSchedule;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Раздел кабинета «Самовывоз» (pick-09): что собрано, что собирается, пропуска курьерам.
 */
class PickupController extends Controller
{
    public function __construct(
        private readonly OrderFulfilmentResolver $resolver,
        private readonly PickupPassService $passes,
        private readonly WarehouseSchedule $schedule,
        private readonly PickupDeskSchedule $desk,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        abort_unless((bool) config('pickup.enabled'), 404);

        $user = $request->user();
        $issues = $this->resolver->issuesForUser($user);
        $companies = Company::query()->where('user_id', $user->id)->pluck('name', 'id');

        // Состав заказов — сразу на экране, спойлером: клиент решает, за чем слать курьера, не открывая карточки.
        $orderIds = $issues->flatMap(fn (GoodsIssue $gi) => collect($gi->getRelation('pickupOrders'))->pluck('id'))->unique()->values();
        $itemsByOrder = $orderIds->isEmpty() ? collect() : \App\Models\OrderItem::query()
            ->whereIn('order_id', $orderIds)->where('cancelled', false)
            ->with('product:id,name,sku')
            ->get(['id', 'order_id', 'product_id', 'name', 'quantity'])
            ->groupBy('order_id');

        $covered = $this->passes->coveredBySelected($user);
        $allPass = $this->passes->activeAllPass($user);

        $rows = $issues->map(function (GoodsIssue $gi) use ($companies, $covered, $allPass, $itemsByOrder) {
            $handover = $gi->activeHandover;
            $stage = $this->resolver->issueStage($gi, true, $handover !== null);
            $orders = collect($gi->getRelation('pickupOrders'));
            $promised = $stage === Stage::PICKING ? $this->schedule->promisedReadyAt($gi->created_at) : null;

            // Какой пропуск покрывает комплект: свой «на выбранное» либо общий «на всё готовое».
            $pass = $covered->get($gi->id) ?? ($stage === Stage::READY ? $allPass : null);

            return [
                'id' => $gi->id,
                'number' => $gi->number,
                'pass_id' => $pass?->id,
                'pass_code' => $pass?->code_display,
                'pass_scope' => $pass?->scope,
                'stage' => $stage->value,
                'stage_label' => $stage->label(),
                'packages_count' => (int) $gi->packages_count,
                'ready_since' => $stage === Stage::READY ? $gi->status_changed_at?->toIso8601String() : null,
                'promised_text' => $promised?->format($promised->isToday() ? 'H:i' : 'd.m H:i'),
                'handed_at' => $handover?->issued_at?->toIso8601String(),
                'recipient_name' => $handover?->recipient_name,
                'company' => $companies->get($orders->first()?->company_id),
                'orders' => $orders->map(fn ($o) => [
                    'id' => $o->id,
                    'number' => $o->clientLabel(),
                    'total_amount' => (float) $o->total_amount,
                    'items' => ($itemsByOrder->get($o->id) ?? collect())->map(fn ($item) => [
                        'name' => $item->product?->name ?: $item->name,
                        'sku' => $item->product?->sku,
                        'quantity' => (float) $item->quantity,
                    ])->values()->all(),
                ])->values()->all(),
            ];
        });

        $weekAgo = now()->subDays(7);

        return Inertia::render('User/Cabinet/Pickup/Index', [
            'ready' => $rows->where('stage', Stage::READY->value)->values(),
            'picking' => $rows->where('stage', Stage::PICKING->value)->values(),
            'handed' => $rows->where('stage', Stage::HANDED_OVER->value)
                ->filter(fn (array $row) => $row['handed_at'] !== null && $row['handed_at'] >= $weekAgo->toIso8601String())
                ->sortByDesc('handed_at')->values(),
            'passes' => PickupPass::query()->where('user_id', $user->id)->usable()->latest()->get()
                ->map(fn (PickupPass $pass) => $this->presentPass($pass))->values(),
            'schedule' => $this->schedule->today(now()),
            'desk' => $this->desk->publicSummary(now()),
            'multiCompany' => $companies->count() > 1,
            'hasAllPass' => $allPass !== null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless((bool) config('pickup.enabled'), 404);

        $data = $request->validate([
            'scope' => ['required', 'in:all,selected'],
            'goods_issue_ids' => ['required_if:scope,selected', 'array', 'max:100'],
            'goods_issue_ids.*' => ['integer'],
            'courier_name' => ['nullable', 'string', 'max:120'],
            'courier_phone' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:250'],
        ], [], [
            'goods_issue_ids' => 'заказы', 'courier_name' => 'имя курьера',
            'courier_phone' => 'телефон курьера', 'note' => 'комментарий',
        ]);

        try {
            [$pass] = $data['scope'] === PickupPass::SCOPE_ALL
                ? $this->passes->issueAll($request->user(), $data)
                : $this->passes->issueSelected($request->user(), $data['goods_issue_ids'] ?? [], $data);
        } catch (PickupPassException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'pass' => $this->presentPass($pass)]);
    }

    public function revoke(Request $request, PickupPass $pass): JsonResponse
    {
        abort_unless((bool) config('pickup.enabled'), 404);
        abort_unless($pass->user_id === $request->user()->id, 403);

        try {
            $this->passes->revoke($pass);
        } catch (PickupPassException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Пропуск отозван. Старая ссылка больше не работает']);
    }

    /** @return array<string, mixed> */
    private function presentPass(PickupPass $pass): array
    {
        $url = $this->passes->urlOf($pass);
        $items = $this->passes->contents($pass);

        return [
            'id' => $pass->id,
            'scope' => $pass->scope,
            'code' => $pass->code_display,
            'url' => $url,
            'qr' => self::qrDataUri($url),
            'expires_at' => $pass->expires_at->toIso8601String(),
            'expires_text' => 'до '.$pass->expires_at->timezone($this->schedule->timezone())->format('d.m H:i'),
            'courier_name' => $pass->courier_name,
            'items' => $items->all(),
            'orders' => $items->flatMap(fn (array $row) => collect($row['orders'])->pluck('number'))->unique()->values()->all(),
            'to_issue' => $items->where('can_issue', true)->count(),
            'packages_to_issue' => (int) $items->where('can_issue', true)->sum('packages_count'),
        ];
    }

    /** QR ссылки пропуска как data-URI SVG — без внешних сервисов и без хранения файлов. */
    public static function qrDataUri(string $url): string
    {
        $options = new QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
            'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
            'quietzoneSize' => 2,
            'outputBase64' => true,
        ]);

        return (new QRCode($options))->render($url);
    }
}
