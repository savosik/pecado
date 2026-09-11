<?php

namespace App\Services\Motivation;

use App\Models\CrmCall;
use App\Models\CrmEmail;
use App\Models\CrmTask;
use App\Models\Motivation\MotivationPartnerAssignment;
use App\Models\Motivation\MotivationPoolPackage;
use App\Models\Motivation\MotivationPoolPackageItem;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Раздача Пула пакетами (карточка mot-35; пп. 8.2–8.4 Положения).
 *
 * Пакет — до N партнёров из Пула одному работнику со сроками: первый контакт
 * за N рабочих дней, первая отгрузка за N календарных. Не уложился — партнёр
 * возвращается в Пул решением руководителя, статус Нового у него сохраняется.
 *
 * Два разных действия при выдаче: карточка партнёра переходит работнику сразу
 * (он ведёт партнёра в CRM с этого дня), а отнесение отгрузок к показателям
 * начинается с первого числа следующего периода (решение заказчика от
 * 08.09.2026: атрибуция внутри месяца не дробится).
 */
class PoolPackageService
{
    public function __construct(
        private readonly PoolListService $pool,
        private readonly PartnerAttributionResolver $attribution,
        private readonly WorkingCalendar $calendar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $quarter = $period->startOfQuarter();

        $poolIds = $this->attribution->poolPartnerIds($period->endOfMonth());
        $withHistory = $poolIds === [] ? 0 : Shipment::query()->withoutInternalOrganizations()->whereIn('user_id', $poolIds)->distinct()->count('user_id');

        $packages = MotivationPoolPackage::query()
            ->with(['manager:id,name', 'items.partner:id,name,erp_name'])
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $taps = [];
        foreach (PersonalManager::query()->where('payroll_enabled', true)->orderBy('name')->get() as $manager) {
            $taps[] = ['manager' => ['id' => (int) $manager->getKey(), 'name' => (string) $manager->name]] + $this->pool->tap((int) $manager->getKey(), $period);
        }

        return [
            'month' => $period->toDateString(),
            'state' => [
                'total' => count($poolIds),
                'with_history' => $withHistory,
                'issued_this_quarter' => MotivationPoolPackageItem::query()->whereHas('package', fn ($q) => $q->whereDate('issued_on', '>=', $quarter))->count(),
                'returned_this_quarter' => MotivationPoolPackageItem::query()->where('outcome', MotivationPoolPackageItem::OUTCOME_RETURNED)->whereDate('returned_to_pool_at', '>=', $quarter)->count(),
                'package_size' => (int) config('motivation.default_parameters.pool_package_size', 20),
                'contact_working_days' => (int) config('motivation.default_parameters.pool_contact_working_days', 10),
                'shipment_days' => (int) config('motivation.default_parameters.pool_shipment_days', 90),
            ],
            'taps' => $taps,
            'packages' => $packages->map(fn (MotivationPoolPackage $p): array => $this->packageRow($p))->all(),
            'note' => sprintf(
                'В общем списке %d партнёров, покупали из них %d. Раздача пакетами по %d из такой базы даёт низкую конверсию — сроки в приказе стоит устанавливать с учётом этого.',
                count($poolIds), $withHistory, (int) config('motivation.default_parameters.pool_package_size', 20),
            ),
        ];
    }

    /**
     * Выдать пакет.
     *
     * @param  list<int>  $partnerIds
     *
     * @throws \InvalidArgumentException
     */
    public function issue(int $managerId, array $partnerIds, User $actor, ?string $comment = null): MotivationPoolPackage
    {
        $today = CarbonImmutable::today();
        $partnerIds = array_values(array_unique(array_map('intval', $partnerIds)));
        $size = (int) config('motivation.default_parameters.pool_package_size', 20);

        if ($partnerIds === []) {
            throw new \InvalidArgumentException('Выберите хотя бы одного партнёра.');
        }

        if (count($partnerIds) > $size) {
            throw new \InvalidArgumentException(sprintf('Предельный размер пакета — %d партнёров, выбрано %d.', $size, count($partnerIds)));
        }

        $tap = $this->pool->tap($managerId, $today->startOfMonth());
        if ($tap['blocked']) {
            throw new \InvalidArgumentException('Выдача этому работнику приостановлена правилом крана (п. 8.4): '.$tap['note']);
        }

        $pool = array_flip($this->attribution->poolPartnerIds($today));
        $notInPool = array_filter($partnerIds, fn (int $id): bool => ! isset($pool[$id]));
        if ($notInPool !== []) {
            throw new \InvalidArgumentException('Часть выбранных партнёров уже закреплена — обновите список.');
        }

        $contactDue = $this->addWorkingDays($today, (int) config('motivation.default_parameters.pool_contact_working_days', 10));
        $shipmentDue = $today->addDays((int) config('motivation.default_parameters.pool_shipment_days', 90));
        $attributionFrom = $today->addMonthNoOverflow()->startOfMonth();

        $package = DB::transaction(function () use ($managerId, $partnerIds, $actor, $comment, $today, $contactDue, $shipmentDue, $attributionFrom): MotivationPoolPackage {
            $package = MotivationPoolPackage::query()->create([
                'personal_manager_id' => $managerId,
                'issued_on' => $today->toDateString(),
                'issued_by' => $actor->getKey(),
                'contact_due_on' => $contactDue->toDateString(),
                'shipment_due_on' => $shipmentDue->toDateString(),
                'status' => MotivationPoolPackage::STATUS_ACTIVE,
                'comment' => $comment,
            ]);

            foreach ($partnerIds as $partnerId) {
                MotivationPoolPackageItem::query()->create(['package_id' => $package->getKey(), 'user_id' => $partnerId]);

                // Карточка — работнику сразу: он ведёт партнёра с этого дня.
                User::query()->whereKey($partnerId)->update(['personal_manager_id' => $managerId]);

                // Реестр: прежняя запись Пула закрывается, показатели — со следующего периода.
                MotivationPartnerAssignment::query()
                    ->where('user_id', $partnerId)->whereNull('ends_on')
                    ->update(['ends_on' => $attributionFrom->subDay()->toDateString()]);
                MotivationPartnerAssignment::query()->create([
                    'user_id' => $partnerId,
                    'personal_manager_id' => $managerId,
                    'starts_on' => $attributionFrom->toDateString(),
                    'reason' => MotivationPartnerAssignment::REASON_POOL_PACKAGE,
                    'comment' => sprintf('Пакет № %d от %s', $package->getKey(), $today->format('d.m.Y')),
                    'author_id' => $actor->getKey(),
                ]);
            }

            return $package;
        });

        Cache::forget('motivation:pool:'.$today->format('Y-m'));

        return $package;
    }

    /**
     * Обновить состояние пакета по данным CRM: контакты, отгрузки, просрочки.
     */
    public function refresh(MotivationPoolPackage $package): MotivationPoolPackage
    {
        $issued = CarbonImmutable::instance($package->issued_on)->startOfDay();

        foreach ($package->items()->with('partner:id')->get() as $item) {
            if ($item->outcome === MotivationPoolPackageItem::OUTCOME_RETURNED) {
                continue;
            }

            $partnerId = (int) $item->user_id;
            $contact = $item->first_contact_at ?? $this->firstContact($partnerId, $issued);
            $shipment = $item->first_shipment_at ?? Shipment::query()->withoutInternalOrganizations()
                ->where('user_id', $partnerId)->where('erp_created_at', '>=', $issued)->min('erp_created_at');

            $item->forceFill([
                'first_contact_at' => $contact,
                'first_shipment_at' => $shipment,
                'outcome' => $shipment !== null ? MotivationPoolPackageItem::OUTCOME_CONVERTED : MotivationPoolPackageItem::OUTCOME_IN_PROGRESS,
            ])->save();
        }

        $open = $package->items()->where('outcome', MotivationPoolPackageItem::OUTCOME_IN_PROGRESS)->exists();
        if (! $open && $package->status === MotivationPoolPackage::STATUS_ACTIVE) {
            $package->forceFill(['status' => MotivationPoolPackage::STATUS_CLOSED])->save();
        }

        return $package->refresh();
    }

    /**
     * Вернуть партнёров пакета в Пул (п. 8.3): статус Нового сохраняется.
     *
     * @param  list<int>  $itemIds
     */
    public function returnToPool(MotivationPoolPackage $package, array $itemIds, User $actor): int
    {
        $today = CarbonImmutable::today();
        $count = 0;

        DB::transaction(function () use ($package, $itemIds, $actor, $today, &$count): void {
            foreach ($package->items()->whereIn('id', $itemIds)->where('outcome', '<>', MotivationPoolPackageItem::OUTCOME_RETURNED)->get() as $item) {
                $item->forceFill(['outcome' => MotivationPoolPackageItem::OUTCOME_RETURNED, 'returned_to_pool_at' => now()])->save();

                User::query()->whereKey($item->user_id)->update(['personal_manager_id' => null]);

                MotivationPartnerAssignment::query()
                    ->where('user_id', $item->user_id)->whereNull('ends_on')
                    ->update(['ends_on' => $today->toDateString()]);
                MotivationPartnerAssignment::query()->create([
                    'user_id' => (int) $item->user_id,
                    'personal_manager_id' => null,
                    'starts_on' => $today->addDay()->toDateString(),
                    'reason' => MotivationPartnerAssignment::REASON_RETURN_TO_POOL,
                    'comment' => sprintf('Возврат из пакета № %d: сроки не выполнены', $package->getKey()),
                    'author_id' => $actor->getKey(),
                ]);
                $count++;
            }
        });

        $this->refresh($package);
        Cache::forget('motivation:pool:'.$today->format('Y-m'));

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function packageRow(MotivationPoolPackage $package): array
    {
        $today = CarbonImmutable::today();
        $contactDue = CarbonImmutable::instance($package->contact_due_on)->startOfDay();
        $shipmentDue = CarbonImmutable::instance($package->shipment_due_on)->startOfDay();
        $items = [];
        $contactedInTime = 0;
        $shipped = 0;
        $overdue = 0;

        foreach ($package->items as $item) {
            $contactAt = $item->first_contact_at === null ? null : CarbonImmutable::instance($item->first_contact_at);
            $contactInTime = $contactAt !== null && $contactAt->lte($contactDue->endOfDay());
            $returned = $item->outcome === MotivationPoolPackageItem::OUTCOME_RETURNED;
            $converted = $item->outcome === MotivationPoolPackageItem::OUTCOME_CONVERTED;
            $late = ! $returned && ! $converted && (
                ($contactAt === null && $today->greaterThan($contactDue))
                || ($item->first_shipment_at === null && $today->greaterThan($shipmentDue))
            );

            $contactedInTime += $contactInTime ? 1 : 0;
            $shipped += $converted ? 1 : 0;
            $overdue += $late ? 1 : 0;

            $items[] = [
                'id' => (int) $item->getKey(),
                'partner_id' => (int) $item->user_id,
                'partner_name' => (string) ($item->partner->display_name ?? $item->partner->name ?? ('#'.$item->user_id)),
                'first_contact_at' => $item->first_contact_at?->toIso8601String(),
                'contact_in_time' => $contactInTime,
                'first_shipment_at' => $item->first_shipment_at?->toIso8601String(),
                'outcome' => $item->outcome,
                'late' => $late,
            ];
        }

        return [
            'id' => (int) $package->getKey(),
            'manager' => ['id' => (int) $package->personal_manager_id, 'name' => $package->manager === null ? '' : (string) $package->manager->name],
            'issued_on' => $package->issued_on->toDateString(),
            'contact_due_on' => $package->contact_due_on->toDateString(),
            'shipment_due_on' => $package->shipment_due_on->toDateString(),
            'status' => $package->status,
            'comment' => $package->comment,
            'count' => count($items),
            'contacted_in_time' => $contactedInTime,
            'shipped' => $shipped,
            'overdue' => $overdue,
            'items' => $items,
        ];
    }

    private function firstContact(int $partnerId, CarbonImmutable $since): ?string
    {
        $dates = array_filter([
            CrmCall::query()->where('client_user_id', $partnerId)->where('started_at', '>=', $since)->min('started_at'),
            CrmEmail::query()->where('client_user_id', $partnerId)->whereNotNull('sent_at')->where('sent_at', '>=', $since)->min('sent_at'),
            CrmTask::query()->where('client_user_id', $partnerId)->where('created_at', '>=', $since)->min('created_at'),
        ]);

        return $dates === [] ? null : (string) min(array_map('strval', $dates));
    }

    private function addWorkingDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $day = $from;
        for ($counted = 0; $counted < $days;) {
            $day = $day->addDay();
            if ($this->calendar->isWorkingDay($day)) {
                $counted++;
            }
        }

        return $day;
    }
}
