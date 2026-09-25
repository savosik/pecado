<?php

namespace App\Services\Pickup;

use App\Enums\OrderFulfilmentStage as Stage;
use App\Models\GoodsIssue;
use App\Models\Pickup\PickupPass;
use App\Models\User;
use App\Services\Warehouse\WarehouseSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Пропуск курьеру (pick-09): QR-ссылка и шестизначный код, которые клиент пересылает курьеру.
 *
 * Два охвата: `all` — всё готовое на момент скана (состав не хранится) и `selected` — выбранные
 * расходные ордера. Состав всегда пересчитывается при чтении: ордер мог вернуться в сборку,
 * быть отменён или уже выдан.
 */
class PickupPassService
{
    public function __construct(
        private readonly OrderFulfilmentResolver $resolver,
        private readonly WarehouseSchedule $schedule,
    ) {}

    /**
     * @param  array{courier_name?: ?string, courier_phone?: ?string, note?: ?string}  $details
     * @return array{0: PickupPass, 1: string} пропуск и секретный токен ссылки
     */
    public function issueAll(User $user, array $details = [], string $source = 'cabinet'): array
    {
        // Один комплект — один пропуск: второй пропуск «на всё» пересекался бы с первым целиком.
        $existing = $this->activeAllPass($user);
        if ($existing !== null) {
            throw PickupPassException::allPassExists($existing->code_display);
        }

        $covered = $this->coveredBySelected($user);
        if ($this->resolver->readyForUser($user)->reject(fn (GoodsIssue $gi) => $covered->has($gi->id))->isEmpty()) {
            throw PickupPassException::nothingReady();
        }

        return $this->create($user, PickupPass::SCOPE_ALL, [], $details, $source);
    }

    /**
     * @param  array<int, int>  $goodsIssueIds
     * @param  array{courier_name?: ?string, courier_phone?: ?string, note?: ?string}  $details
     * @return array{0: PickupPass, 1: string}
     */
    public function issueSelected(User $user, array $goodsIssueIds, array $details = [], string $source = 'cabinet'): array
    {
        $ids = collect($goodsIssueIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            throw PickupPassException::nothingReady();
        }

        // Принадлежность проверяется через заказы клиента, а не через шапку ордера.
        $ready = $this->resolver->readyForUser($user)->keyBy('id');
        if ($ids->diff($ready->keys())->isNotEmpty()) {
            throw PickupPassException::notYours();
        }

        // Комплект, уже отданный другому пропуску «на выбранное», второму курьеру не достаётся.
        $covered = $this->coveredBySelected($user);
        $taken = $ids->filter(fn (int $id) => $covered->has($id));
        if ($taken->isNotEmpty()) {
            throw PickupPassException::overlap($taken->map(fn (int $id) => $this->issueLabel($ready->get($id)))->all());
        }

        return $this->create($user, PickupPass::SCOPE_SELECTED, $ids->all(), $details, $source);
    }

    public function revoke(PickupPass $pass): PickupPass
    {
        if (! $pass->isUsable()) {
            throw PickupPassException::notActive();
        }

        $pass->update(['status' => PickupPass::STATUS_REVOKED, 'revoked_at' => now()]);

        return $pass;
    }

    public function findByToken(string $token): ?PickupPass
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return PickupPass::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /** Только действующий: код короткий, недействующие пропуска по нему не раскрываем. */
    public function findUsableByCode(string $code): ?PickupPass
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        return strlen($code) === 6 ? PickupPass::query()->usable()->where('code', $code)->first() : null;
    }

    public function tokenOf(PickupPass $pass): string
    {
        return Crypt::decryptString($pass->token_encrypted);
    }

    public function urlOf(PickupPass $pass): string
    {
        return route('pickup.pass', ['token' => $this->tokenOf($pass)]);
    }

    /**
     * Что сейчас можно отдать по пропуску — всегда с актуальным состоянием ордеров.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function contents(PickupPass $pass): Collection
    {
        $user = $pass->user;
        // «На всё готовое» — всё, что не отдано другим пропускам на выбранные заказы: без пересечений.
        $covered = $pass->scope === PickupPass::SCOPE_ALL ? $this->coveredBySelected($user, exceptPassId: $pass->id) : collect();
        $issues = $pass->scope === PickupPass::SCOPE_ALL
            ? $this->resolver->readyForUser($user)->reject(fn (GoodsIssue $gi) => $covered->has($gi->id))->values()
            : $this->selectedIssues($pass, $user);

        return $issues->map(function (GoodsIssue $gi) {
            $handover = $gi->activeHandover;
            $stage = $gi->trashed() ? null : $this->resolver->issueStage($gi, true, $handover !== null);

            [$state, $stateLabel] = match (true) {
                $gi->trashed() => ['cancelled', 'Ордер отменён в 1С'],
                $stage === Stage::HANDED_OVER => ['handed', 'Уже выдал '.($handover->issuer?->name ?? 'кладовщик').' в '.$handover->issued_at->format($handover->issued_at->isToday() ? 'H:i' : 'd.m H:i')],
                $stage === Stage::READY => ['ready', 'Можно выдавать'],
                $stage === Stage::PICKING => ['picking', 'Ещё собирается'],
                default => ['unavailable', 'Недоступен для выдачи'],
            };

            return [
                'goods_issue_id' => $gi->id,
                'number' => $gi->number,
                'packages_count' => (int) $gi->packages_count,
                'state' => $state,
                'state_label' => $stateLabel,
                'can_issue' => $state === 'ready',
                'ready_since' => $state === 'ready' ? $gi->status_changed_at?->toIso8601String() : null,
                'orders' => collect($gi->getRelation('pickupOrders'))->map(fn ($o) => [
                    'id' => $o->id,
                    'number' => $o->erp_number ?: $o->number,
                    'site_number' => $o->number,
                    'company_id' => $o->company_id,
                ])->values()->all(),
            ];
        })->values();
    }

    /** После выдачи: если по пропуску больше нечего отдавать — он использован. */
    public function closeIfComplete(PickupPass $pass): void
    {
        if ($pass->status !== PickupPass::STATUS_ACTIVE) {
            return;
        }

        $left = $this->contents($pass)->whereIn('state', ['ready', 'picking'])->count();
        if ($left === 0) {
            $pass->update(['status' => PickupPass::STATUS_USED, 'used_at' => now()]);
        }
    }

    /** Действующий пропуск клиента «на всё готовое», если есть. */
    public function activeAllPass(User $user): ?PickupPass
    {
        return PickupPass::query()->where('user_id', $user->id)->usable()->where('scope', PickupPass::SCOPE_ALL)->latest()->first();
    }

    /**
     * Комплекты, занятые действующими пропусками «на выбранное»: goods_issue_id → пропуск.
     *
     * @return Collection<int, PickupPass>
     */
    public function coveredBySelected(User $user, ?int $exceptPassId = null): Collection
    {
        $passes = PickupPass::query()->where('user_id', $user->id)->usable()->where('scope', PickupPass::SCOPE_SELECTED)
            ->when($exceptPassId, fn ($q) => $q->where('id', '!=', $exceptPassId))
            ->with('items')->get();

        $map = collect();
        foreach ($passes as $pass) {
            foreach ($pass->items as $item) {
                $map->put($item->goods_issue_id, $pass);
            }
        }

        return $map;
    }

    private function issueLabel(GoodsIssue $gi): string
    {
        $numbers = collect($gi->getRelation('pickupOrders'))->map(fn ($o) => $o->erp_number ?: $o->number);

        return $numbers->isNotEmpty() ? $numbers->join(', ') : 'ордер '.$gi->number;
    }

    /** @return Collection<int, GoodsIssue> */
    private function selectedIssues(PickupPass $pass, User $user): Collection
    {
        $ids = $pass->items()->pluck('goods_issue_id');
        $known = $this->resolver->issuesForUser($user)->whereIn('id', $ids)->keyBy('id');

        // Отменённые в 1С ордера тоже показываем — с причиной, почему не выдаём.
        $trashed = GoodsIssue::onlyTrashed()->whereIn('id', $ids->diff($known->keys()))->get()
            ->each(fn (GoodsIssue $gi) => $gi->setRelation('pickupOrders', collect())->setRelation('activeHandover', null));

        return $known->values()->concat($trashed);
    }

    /**
     * @param  array<int, int>  $goodsIssueIds
     * @param  array<string, mixed>  $details
     * @return array{0: PickupPass, 1: string}
     */
    private function create(User $user, string $scope, array $goodsIssueIds, array $details, string $source): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $pass = DB::transaction(function () use ($user, $scope, $goodsIssueIds, $details, $source, $token) {
            $pass = PickupPass::create([
                'user_id' => $user->id,
                'scope' => $scope,
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => Crypt::encryptString($token),
                'code' => $this->freeCode(),
                'status' => PickupPass::STATUS_ACTIVE,
                'expires_at' => $this->schedule->endOfWorkingDay(now(), max(1, (int) config('pickup.pass_ttl_working_days', 3))),
                'courier_name' => $this->clean($details['courier_name'] ?? null),
                'courier_phone' => $this->clean($details['courier_phone'] ?? null),
                'note' => $this->clean($details['note'] ?? null),
                'source' => $source,
            ]);

            foreach ($goodsIssueIds as $id) {
                $pass->items()->create(['goods_issue_id' => $id]);
            }

            return $pass;
        });

        return [$pass, $token];
    }

    /** Код уникален среди действующих и не повторяется 30 дней: старый скриншот не сработает на чужой пропуск. */
    private function freeCode(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $taken = PickupPass::query()->where('code', $code)
                ->where(fn ($q) => $q->where('created_at', '>=', now()->subDays(30))->orWhere(fn ($u) => $u->where('status', PickupPass::STATUS_ACTIVE)->where('expires_at', '>', now())))
                ->exists();
            if (! $taken) {
                return $code;
            }
        }

        throw new \RuntimeException('Не удалось подобрать свободный код пропуска');
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : mb_substr($value, 0, 250);
    }
}
