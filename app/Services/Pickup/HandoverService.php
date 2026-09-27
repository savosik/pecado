<?php

namespace App\Services\Pickup;

use App\Models\GoodsIssue;
use App\Models\Pickup\PickupHandover;
use App\Models\Pickup\PickupPass;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Отметка «выдан» на складе (pick-06). В 1С такого статуса нет — документ живёт только на сайте.
 *
 * Двойная выдача закрыта дважды: блокировкой ордера в транзакции и уникальным индексом по
 * генерируемой колонке `active_key` (на случай гонки двух телефонов).
 */
class HandoverService
{
    /**
     * @param  array{recipient_name?: ?string, comment?: ?string, box_verified?: bool}  $details
     */
    public function issue(GoodsIssue $goodsIssue, User $by, string $method, ?PickupPass $pass = null, array $details = []): PickupHandover
    {
        try {
            return DB::transaction(function () use ($goodsIssue, $by, $method, $pass, $details) {
                /** @var GoodsIssue|null $locked */
                $locked = GoodsIssue::withTrashed()->lockForUpdate()->find($goodsIssue->id);

                if ($locked === null || $locked->trashed()) {
                    throw HandoverException::deleted();
                }

                $existing = $locked->activeHandover()->with('issuer:id,name')->first();
                if ($existing !== null) {
                    throw $this->alreadyIssued($existing);
                }

                if ($method !== PickupHandover::METHOD_BACKFILL && $locked->status !== GoodsIssue::STATUS_SHIPPED) {
                    throw HandoverException::notReady(GoodsIssue::STATUS_LABELS[$locked->status] ?? $locked->status);
                }

                $handover = PickupHandover::create([
                    'goods_issue_id' => $locked->id,
                    'pickup_pass_id' => $pass?->id,
                    'issued_by' => $by->id,
                    'issued_at' => now(),
                    'method' => $method,
                    'recipient_name' => $this->clean($details['recipient_name'] ?? null) ?? $pass?->courier_name,
                    'packages_count' => (int) $locked->packages_count,
                    'box_verified' => (bool) ($details['box_verified'] ?? false),
                    'comment' => $this->clean($details['comment'] ?? null),
                ]);

                event(new \App\Events\Pickup\GoodsIssueHandedOver($handover));

                return $handover;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $goodsIssue->activeHandover()->with('issuer:id,name')->first();

            throw $existing ? $this->alreadyIssued($existing) : HandoverException::alreadyIssued('другой кладовщик', now()->format('H:i'));
        }
    }

    /** Закрыть хвост до запуска: ордер выдали раньше, чем появился экран. */
    public function closeWithoutHandover(GoodsIssue $goodsIssue, User $by, ?string $comment = null): PickupHandover
    {
        return $this->issue($goodsIssue, $by, PickupHandover::METHOD_BACKFILL, null, ['comment' => $comment ?? 'Выдан до запуска экрана выдачи']);
    }

    public function cancel(PickupHandover $handover, User $by, ?string $reason): PickupHandover
    {
        $reason = $this->clean($reason);
        if ($reason === null) {
            throw HandoverException::reasonRequired();
        }

        return DB::transaction(function () use ($handover, $by, $reason) {
            /** @var PickupHandover $locked */
            $locked = PickupHandover::query()->lockForUpdate()->findOrFail($handover->id);

            if ($locked->cancelled_at !== null) {
                throw HandoverException::notIssued();
            }

            $hours = max(1, (int) config('pickup.cancel_window_hours', 24));
            if ($locked->issued_at->lt(now()->subHours($hours))) {
                throw HandoverException::cancelWindowClosed($hours);
            }

            $locked->update(['cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason]);

            // Пропуск, закрытый этой выдачей, снова действует: курьер ещё не получил комплект.
            $pass = $locked->pass;
            if ($pass !== null && $pass->status === PickupPass::STATUS_USED) {
                $pass->update(['status' => PickupPass::STATUS_ACTIVE, 'used_at' => null]);
            }

            return $locked;
        });
    }

    /** Ордер откатился из «отгружен» после выдачи: выдачу не трогаем, помечаем на разбор. */
    public function flagRollback(GoodsIssue $goodsIssue, string $toStatus): void
    {
        $goodsIssue->activeHandover()
            ->where('method', '!=', PickupHandover::METHOD_BACKFILL)
            ->update([
                'needs_review' => true,
                'review_note' => 'После выдачи ордер в 1С перешёл в статус «'.(GoodsIssue::STATUS_LABELS[$toStatus] ?? $toStatus).'»',
            ]);
    }

    public function resolveReview(PickupHandover $handover, User $by, string $note): PickupHandover
    {
        $handover->update([
            'needs_review' => false,
            'review_note' => trim($handover->review_note.' · Разобрано ('.$by->name.'): '.trim($note)),
            'reviewed_at' => now(),
        ]);

        return $handover;
    }

    private function alreadyIssued(PickupHandover $existing): HandoverException
    {
        return HandoverException::alreadyIssued(
            $existing->issuer?->name ?? 'другой кладовщик',
            $existing->issued_at->isToday() ? $existing->issued_at->format('H:i') : $existing->issued_at->format('d.m H:i'),
        );
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
