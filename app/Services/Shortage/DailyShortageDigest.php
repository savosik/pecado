<?php

namespace App\Services\Shortage;

use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\ManagerAbsenceResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Вечерняя сводка недоборов: что за день отменилось и до сих пор не разнесено.
 *
 * Повод для письма — именно неразнесённые строки: менеджер, разобравший недобор
 * до 17:00, письма не получает. Иначе рассылка превратилась бы в фоновый шум,
 * который перестают открывать.
 *
 * Адресат — тот, кто фактически ведёт клиента сегодня: при активном отсутствии
 * менеджера сводка уходит замещающему (см. {@see ManagerAbsenceResolver}).
 * Партнёры без персонального менеджера в рассылку не попадают — адресовать
 * такую строку некому, она остаётся видна в разделе.
 */
class DailyShortageDigest
{
    public function __construct(
        private readonly ManagerAbsenceResolver $absences,
    ) {}

    /**
     * Неразнесённые отмены дня, сгруппированные по получателю письма.
     *
     * Ключи группы: recipient (User — кому письмо), manager (PersonalManager —
     * кто ведёт клиентов сегодня), on_behalf_of (PersonalManager|null — кого
     * замещает), items (Collection<int, OrderItem>), lines_count, quantity,
     * amount, orders_count. Вложенный дженерик в сигнатуре не указан намеренно:
     * Collection у Laravel инвариантна, и PHPStan отвергает точный тип.
     *
     * @return list<array<string, mixed>>
     */
    public function forDay(?CarbonInterface $day = null): array
    {
        $day = Carbon::parse($day ?? Carbon::today());

        $items = OrderItem::query()
            ->where('order_items.cancelled', true)
            ->whereNull('order_items.cancel_reason_id')
            ->whereNull('order_items.cancel_archived_at')
            ->whereBetween('order_items.cancelled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->whereHas('order', fn ($q) => $q->whereNull('orders.deleted_at'))
            ->with([
                'order:id,number,erp_number,user_id,company_id,erp_created_at,created_at',
                'order.user:id,name,erp_name,personal_manager_id',
                'order.company:id,name',
                'product:id,name,sku',
            ])
            ->orderByDesc('order_items.cancelled_at')
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        $managers = PersonalManager::query()
            ->whereKey($items->pluck('order.user.personal_manager_id')->filter()->unique()->all())
            ->with('user')
            ->get()
            ->keyBy('id');

        /** @var Collection<int, array{recipient: User, manager: PersonalManager, on_behalf_of: PersonalManager|null, items: Collection<int, OrderItem>}> $groups */
        $groups = collect();

        foreach ($items as $item) {
            $managerId = $item->order?->user?->personal_manager_id;
            $manager = $managerId !== null ? $managers->get($managerId) : null;

            if ($manager === null) {
                continue;
            }

            // Кто ведёт клиента сегодня: сам менеджер или замещающий его коллега.
            $effective = $this->absences->effectiveManager($manager, $day);
            $recipient = $effective->user;

            if ($recipient === null || blank($recipient->email)) {
                continue;
            }

            $key = $recipient->id;

            if (! $groups->has($key)) {
                $groups->put($key, [
                    'recipient' => $recipient,
                    'manager' => $effective,
                    'on_behalf_of' => $effective->id === $manager->id ? null : $manager,
                    'items' => collect(),
                ]);
            }

            $groups[$key]['items']->push($item);
        }

        return $groups->map(function (array $group) {
            /** @var Collection<int, OrderItem> $items */
            $items = $group['items'];

            return [
                'recipient' => $group['recipient'],
                'manager' => $group['manager'],
                'on_behalf_of' => $group['on_behalf_of'],
                'items' => $items,
                'lines_count' => $items->count(),
                'quantity' => (int) $items->sum('quantity'),
                'amount' => (float) $items->sum('subtotal'),
                'orders_count' => $items->pluck('order_id')->unique()->count(),
            ];
        })->values()->all();
    }
}
