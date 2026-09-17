<?php

namespace App\Http\Controllers\Wms;

use App\Models\GoodsIssue;
use App\Models\Pickup\PickupHandover;
use App\Models\Pickup\PickupPass;
use App\Services\Pickup\HandoverException;
use App\Services\Pickup\HandoverService;
use App\Services\Pickup\PickupPassService;
use App\Services\Pickup\PickupQueue;
use App\Services\Pickup\ScanResolver;
use App\Services\Warehouse\WarehouseSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Выдача заказов самовывоза (эпик pick-00): список к выдаче, скан пропуска, отметка «выдан».
 *
 * Экран рассчитан на телефон у стойки выдачи, поэтому все действия — JSON без перезагрузки
 * страницы (образец — {@see DefectController::resolveBarcode()}).
 */
class PickupController extends WmsController
{
    public function __construct(
        private readonly PickupQueue $queue,
        private readonly HandoverService $handovers,
        private readonly PickupPassService $passes,
        private readonly ScanResolver $scans,
        private readonly WarehouseSchedule $schedule,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();

        return Inertia::render('Wms/Pages/Pickups/Index', $this->payload());
    }

    /** Те же данные для автообновления списка. */
    public function data(): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json($this->payload());
    }

    public function search(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(['rows' => $this->strip($this->queue->search((string) $request->query('q', '')))]);
    }

    /** Что отсканировано: пропуск, код или штрихкод расходного листа. */
    public function resolve(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['value' => ['required', 'string', 'max:512']], [], ['value' => 'скан']);

        $result = $this->scans->resolve($data['value'], $this->wmsActor($request));

        return match ($result['kind']) {
            'pass' => response()->json(['kind' => 'pass', 'via' => $result['via'], 'pass' => $this->presentPass($result['pass'])]),
            'goods_issue' => response()->json(['kind' => 'goods_issue', 'rows' => $this->strip($this->queue->present($result['goods_issues']))]),
            'throttled' => response()->json(['kind' => 'throttled', 'message' => $result['message']], 429),
            default => response()->json(['kind' => 'miss', 'message' => $result['message'] ?? 'Не распознано'], 404),
        };
    }

    public function pass(PickupPass $pass): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(['pass' => $this->presentPass($pass)]);
    }

    public function issue(Request $request, GoodsIssue $goodsIssue): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'pass_id' => ['nullable', 'integer', 'exists:pickup_passes,id'],
            'via' => ['nullable', 'in:qr,code,barcode,manual'],
            'recipient_name' => ['nullable', 'string', 'max:250'],
            'comment' => ['nullable', 'string', 'max:500'],
            'box_verified' => ['nullable', 'boolean'],
        ], [], ['recipient_name' => 'имя курьера', 'comment' => 'комментарий']);

        $pass = isset($data['pass_id']) ? PickupPass::find($data['pass_id']) : null;

        if ($pass !== null) {
            $allowed = $pass->isUsable()
                && $this->passes->contents($pass)->where('goods_issue_id', $goodsIssue->id)->where('can_issue', true)->isNotEmpty();
            if (! $allowed) {
                return response()->json(['reason' => 'not_in_pass', 'message' => 'Этот комплект нельзя выдать по этому пропуску'], 422);
            }
        }

        try {
            $handover = $this->handovers->issue(
                $goodsIssue,
                $this->wmsActor($request),
                $pass ? ($data['via'] ?? PickupHandover::METHOD_QR) : ($data['via'] ?? PickupHandover::METHOD_MANUAL),
                $pass,
                $data,
            );
        } catch (HandoverException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'handover_id' => $handover->id,
            'message' => 'Выдано: ордер '.$goodsIssue->number,
            'pass' => $pass ? $this->presentPass($pass->fresh()) : null,
        ]);
    }

    /** «Выдать всё» по пропуску: каждый комплект отдельной выдачей, частичный успех возможен. */
    public function issueAll(Request $request, PickupPass $pass): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'via' => ['nullable', 'in:qr,code'],
            'recipient_name' => ['nullable', 'string', 'max:250'],
            'verified' => ['nullable', 'array'],
            'verified.*' => ['integer'],
        ]);

        if (! $pass->isUsable()) {
            return response()->json(['reason' => 'not_active', 'message' => 'Пропуск не действует: '.mb_strtolower($pass->status_label)], 422);
        }

        $issued = 0;
        $errors = [];
        foreach ($this->passes->contents($pass)->where('can_issue', true) as $row) {
            try {
                $this->handovers->issue(GoodsIssue::findOrFail($row['goods_issue_id']), $this->wmsActor($request), $data['via'] ?? PickupHandover::METHOD_QR, $pass, [
                    'recipient_name' => $data['recipient_name'] ?? null,
                    'box_verified' => in_array($row['goods_issue_id'], $data['verified'] ?? [], true),
                ]);
                $issued++;
            } catch (HandoverException $e) {
                $errors[] = $row['number'].': '.$e->getMessage();
            }
        }

        return response()->json([
            'ok' => $issued > 0,
            'issued' => $issued,
            'errors' => $errors,
            'message' => $issued > 0 ? "Выдано комплектов: {$issued}" : 'Выдавать нечего',
            'pass' => $this->presentPass($pass->fresh()),
        ], $issued > 0 ? 200 : 422);
    }

    public function cancel(Request $request, PickupHandover $handover): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'причина отмены']);

        try {
            $this->handovers->cancel($handover, $this->wmsActor($request), $data['reason']);
        } catch (HandoverException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Выдача отменена']);
    }

    /** Хвост до запуска экрана: ордер уже отдан, отметки нет. */
    public function close(Request $request, GoodsIssue $goodsIssue): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);

        try {
            $this->handovers->closeWithoutHandover($goodsIssue, $this->wmsActor($request), $data['comment'] ?? null);
        } catch (HandoverException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Закрыто без выдачи']);
    }

    public function review(Request $request, PickupHandover $handover): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:400']], [], ['note' => 'итог разбора']);

        $this->handovers->resolveReview($handover, $this->wmsActor($request), $data['note']);

        return response()->json(['ok' => true, 'message' => 'Отмечено как разобранное']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'awaiting' => $this->strip($this->queue->awaiting()),
            'picking' => $this->strip($this->queue->picking()),
            'issued' => $this->queue->issuedToday()->map(fn (array $h) => $this->stripHandover($h))->all(),
            'stale' => $this->strip($this->queue->stale()),
            'review' => $this->queue->needsReview()->map(fn (array $h) => $this->stripHandover($h))->all(),
            'schedule' => $this->schedule->today(now()),
            'staleDays' => (int) config('pickup.stale_days', 3),
        ];
    }

    /** @return array<string, mixed> */
    private function presentPass(PickupPass $pass): array
    {
        $pass->loadMissing('user:id,name,erp_name,phone');
        $items = $this->passes->contents($pass);

        return [
            'id' => $pass->id,
            'code' => $pass->code_display,
            'scope' => $pass->scope,
            'status' => $pass->effective_status,
            'status_label' => $pass->status_label,
            'is_usable' => $pass->isUsable(),
            'expires_at' => $pass->expires_at->toIso8601String(),
            'client' => $pass->user?->erp_name ?: $pass->user?->name,
            'client_phone' => $pass->user?->phone,
            'courier_name' => $pass->courier_name,
            'courier_phone' => $pass->courier_phone,
            'note' => $pass->note,
            'items' => $items->all(),
            'to_issue' => $items->where('can_issue', true)->count(),
            'packages_to_issue' => (int) $items->where('can_issue', true)->sum('packages_count'),
        ];
    }

    /** Служебные поля презентера наружу не отдаём. */
    private function strip(\Illuminate\Support\Collection $rows): array
    {
        return $rows->map(fn (array $row) => array_diff_key($row, ['_created_at' => true]))->values()->all();
    }

    /** @param array<string, mixed> $handover */
    private function stripHandover(array $handover): array
    {
        if (is_array($handover['goods_issue'] ?? null)) {
            unset($handover['goods_issue']['_created_at']);
        }

        return $handover;
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('pickup.wms_enabled'), 404);
    }
}
