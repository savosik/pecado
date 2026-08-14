<?php

namespace App\Services\Substitution;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SubstitutionOffer;
use App\Models\SubstitutionOfferItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Рождение и дополнение подборок замен по отменам строк из 1С.
 *
 * Одна открытая подборка на заказ: 1С проверяет сборку в несколько заходов,
 * и вторая волна отмен по тому же заказу дополняет существующую подборку,
 * а не плодит новые. Подтверждённая подборка закрыта — следующая волна
 * рождает новую.
 *
 * Недобор не должен зависнуть ничьим: без персонального менеджера задача
 * уходит руководителю отдела (правило «по данным, не по ролям» здесь
 * упирается в то, что задача обязана иметь исполнителя).
 */
class SubstitutionOfferService
{
    public function __construct(
        private readonly ShortageEmailDraftService $drafts,
        private readonly SubstitutionCandidateService $candidates,
    ) {}

    /**
     * Зарегистрировать волну отмен: создать или дополнить подборку, поставить задачу.
     *
     * @param  list<int>  $orderItemIds  строки, отменённые этой волной
     */
    public function registerCancellation(Order $order, array $orderItemIds): ?SubstitutionOffer
    {
        $order->loadMissing('user.personalManager.user');

        $cancelledItems = $order->items()
            ->whereIn('id', $orderItemIds)
            ->where('cancelled', true)
            ->get();

        if ($cancelledItems->isEmpty()) {
            return null;
        }

        $offer = SubstitutionOffer::query()
            ->where('order_id', $order->id)
            ->open()
            ->latest('id')
            ->first();

        $manager = $order->user?->personalManager?->user;

        if ($offer === null) {
            $offer = SubstitutionOffer::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'company_id' => $order->company_id,
                'manager_user_id' => $manager?->id,
                'expires_at' => now()->addDays((int) config('substitutions.offer_ttl_days', 7)),
            ]);

            // Черновик письма-извинения готовится сразу: менеджер в карточке
            // правит готовый текст, а не пишет с нуля.
            $this->drafts->createDraft($offer);
        }

        // Автоподбор: оффер наполняется кандидатами сразу; менеджер в карточке
        // проверяет и правит, а не собирает с нуля.
        $this->populateCandidates($offer, $cancelledItems->all());

        $this->ensureTask($order, $offer, $cancelledItems->all(), $manager);

        return $offer;
    }

    /**
     * Пересбор кандидатов по всем отменённым строкам заказа — идемпотентный
     * (уникальный ключ строк подборки): вызывается при InsufficientStock
     * на согласовании, когда часть кандидатов кончилась.
     */
    public function refreshCandidates(SubstitutionOffer $offer): void
    {
        $offer->loadMissing('order.items');

        $cancelled = $offer->order?->items->where('cancelled', true)->values() ?? collect();

        $this->populateCandidates($offer, $cancelled->all());
    }

    /**
     * @param  list<OrderItem>  $cancelledItems
     */
    private function populateCandidates(SubstitutionOffer $offer, array $cancelledItems): void
    {
        foreach ($cancelledItems as $line) {
            try {
                $set = $this->candidates->forOrderItem($line);
            } catch (\Throwable $e) {
                Log::warning('Автоподбор замен упал по строке — оффер остаётся с ручным наполнением', [
                    'order_item_id' => $line->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($set->waitAvailable && $line->product_id !== null) {
                SubstitutionOfferItem::query()->firstOrCreate(
                    [
                        'offer_id' => $offer->id,
                        'source_order_item_id' => $line->id,
                        'product_id' => $line->product_id,
                        'product_defect_id' => null,
                    ],
                    [
                        'kind' => \App\Enums\Substitution\CandidateKind::SAME_PRODUCT_WAIT,
                        'reason' => $set->waitReason ?? 'Тот же товар, подождать прихода',
                        'price_snapshot' => $line->final_price,
                        'suggested_quantity' => max(1, (int) $line->quantity),
                    ],
                );
            }

            foreach ($set->candidates as $candidate) {
                SubstitutionOfferItem::query()->firstOrCreate(
                    [
                        'offer_id' => $offer->id,
                        'source_order_item_id' => $line->id,
                        'product_id' => $candidate['product_id'],
                        'product_defect_id' => $candidate['product_defect_id'],
                    ],
                    [
                        'kind' => $candidate['kind'],
                        'reason' => $candidate['reason'],
                        'price_snapshot' => $candidate['price'],
                        'suggested_quantity' => $candidate['suggested_quantity'],
                    ],
                );
            }
        }
    }

    /**
     * Задача менеджеру: одна активная на подборку, повторная волна отмен
     * при живой задаче новую не ставит.
     *
     * @param  list<OrderItem>  $cancelledItems
     */
    private function ensureTask(Order $order, SubstitutionOffer $offer, array $cancelledItems, ?User $manager): void
    {
        $assignee = $manager ?? $this->fallbackAssignee();

        if ($assignee === null) {
            Log::warning('Недобор без исполнителя: нет персонального менеджера и руководителя отдела', [
                'order_id' => $order->id,
                'offer_id' => $offer->id,
            ]);

            return;
        }

        $number = $order->erp_number ?: $order->number;

        $hasActiveTask = CrmTask::query()
            ->where('related_type', (new Order)->getMorphClass())
            ->where('related_id', $order->id)
            ->whereIn('status', [TaskStatus::OPEN, TaskStatus::IN_PROGRESS])
            ->where('title', 'like', 'Недобор по заказу%')
            ->exists();

        if ($hasActiveTask) {
            return;
        }

        $amount = round(array_sum(array_map(
            static fn (OrderItem $item) => (float) $item->subtotal,
            $cancelledItems,
        )), 2);

        $urgent = $amount > (float) config('substitutions.urgent_amount', 10000);

        $task = new CrmTask([
            'title' => sprintf(
                'Недобор по заказу %s — %s, %s ₽, %d поз.',
                $number,
                $order->user?->name ?: 'клиент без карточки',
                number_format($amount, 0, ',', ' '),
                count($cancelledItems),
            ),
            'description' => sprintf(
                "1С отменила строки при сборке. Подборка замен: %s\n%s",
                url("/crm/shortages/{$offer->id}"),
                collect($cancelledItems)
                    ->map(fn (OrderItem $item) => sprintf('— %s, %d шт.', $item->name, $item->quantity))
                    ->implode("\n"),
            ),
            'author_id' => $assignee->id,
            'assignee_id' => $assignee->id,
            'status' => TaskStatus::OPEN,
            'priority' => $urgent ? TaskPriority::HIGH : TaskPriority::NORMAL,
            'due_at' => $this->deadline($urgent),
        ]);

        $task->related()->associate($order);
        $task->save();
    }

    /**
     * Дедлайн: срочный недобор — +2 часа, обычный — до конца рабочего дня.
     * Отмена, приехавшая вечером, не должна получать дедлайн в прошлом.
     */
    private function deadline(bool $urgent): Carbon
    {
        if ($urgent) {
            return now()->addHours(2);
        }

        $endOfDay = now()->setTime((int) config('substitutions.task_deadline_hour', 18), 0);

        return $endOfDay->isPast() ? now()->addHours(2) : $endOfDay;
    }

    /**
     * Фолбэк-исполнитель — руководитель отдела продаж.
     */
    private function fallbackAssignee(): ?User
    {
        return User::role('sales-head')->orderBy('id')->first();
    }
}
