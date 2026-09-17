<?php

namespace App\Services\Client\Api\Operations;

use App\Enums\OrderFulfilmentStage as Stage;
use App\Models\GoodsIssue;
use App\Models\Pickup\PickupPass;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Pickup\OrderFulfilmentResolver;
use App\Services\Pickup\PickupPassService;
use App\Services\Warehouse\WarehouseSchedule;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Самовывоз (эпик pick-00): что собрано и ждёт курьера, график склада, пропуска.
 *
 * Общая логика — {@see PickupPassService} и {@see OrderFulfilmentResolver}: агент видит и делает
 * ровно то же, что клиент в разделе кабинета «Самовывоз». Стадия каждого заказа — блок
 * `fulfilment` в `orders.list` и `orders.get`.
 */
class PickupOperations implements OperationProvider
{
    public function __construct(
        private readonly OrderFulfilmentResolver $resolver,
        private readonly PickupPassService $passes,
        private readonly WarehouseSchedule $schedule,
    ) {}

    public static function section(): array
    {
        return ['pickup', 'Самовывоз'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'pickup.schedule',
                section: 'pickup',
                method: 'GET',
                uri: 'pickup/schedule',
                summary: 'График склада, адрес выдачи и обещание времени сборки на сейчас',
                description: 'Часы работы склада, отсечка приёма к сборке «на сегодня» и честное обещание: к какому '
                    .'времени будет собран заказ, отправленный в отгрузку прямо сейчас (`promise`). После отсечки сборка '
                    .'переносится на следующее открытие склада. Спрашивайте перед тем, как обещать покупателю срок.',
                params: [],
                handler: [self::class, 'schedule'],
                gate: FeatureGate::PICKUP,
            ),
            new Operation(
                id: 'pickup.ready',
                section: 'pickup',
                method: 'GET',
                uri: 'pickup/ready',
                summary: 'Что можно забирать: собранные комплекты самовывоза и то, что ещё собирается',
                description: 'Единица выдачи — комплект (расходный ордер склада): несколько заказов могут быть собраны '
                    .'вместе и выдаются только целиком. `ready` — собрано и ждёт курьера, `picking` — в сборке с ожидаемым '
                    .'временем. За комплектами из `ready` можно отправлять курьера, выпустив пропуск (`pickup.passes.create`).',
                params: [],
                handler: [self::class, 'ready'],
                gate: FeatureGate::PICKUP,
            ),
            new Operation(
                id: 'pickup.passes.list',
                section: 'pickup',
                method: 'GET',
                uri: 'pickup/passes',
                summary: 'Действующие пропуска курьерам',
                description: 'Пропуск — ссылка с QR-кодом и шестизначный код, которые курьер показывает на складе. '
                    .'Склад выдаёт только то, что в пропуске. Состав пересчитывается на момент запроса.',
                params: [],
                handler: [self::class, 'passes'],
                gate: FeatureGate::PICKUP,
            ),
            new Operation(
                id: 'pickup.passes.create',
                section: 'pickup',
                method: 'POST',
                uri: 'pickup/passes',
                summary: 'Выпустить пропуск курьеру',
                description: '`scope=all` — на всё, что готово на момент приезда курьера (подхватит и дособранное позже); '
                    .'`scope=selected` — только на комплекты из `goods_issue_ids` (id из `pickup.ready`), остальные дождутся '
                    .'другого курьера. В ответе `url` — ссылку нужно переслать курьеру; по ней не видно ни клиента, ни сумм. '
                    .'Пропуск действует три рабочих дня склада. Если готового нет — 422 nothing_ready.',
                params: [
                    Param::string('scope', 'Охват пропуска', required: true, enum: [PickupPass::SCOPE_ALL, PickupPass::SCOPE_SELECTED]),
                    Param::list('goods_issue_ids', 'Комплекты для scope=selected (id из pickup.ready)', 'integer'),
                    Param::string('courier_name', 'Имя курьера — увидит кладовщик', rules: ['max:120']),
                    Param::string('courier_phone', 'Телефон курьера', rules: ['max:32']),
                    Param::string('note', 'Комментарий для склада', rules: ['max:250']),
                ],
                handler: [self::class, 'create'],
                mutating: true,
                // Создание, как и у адресов и возвратов: повтор с тем же ключом не плодит пропуска.
                idempotent: true,
                gate: FeatureGate::PICKUP,
            ),
            new Operation(
                id: 'pickup.passes.revoke',
                section: 'pickup',
                method: 'POST',
                uri: 'pickup/passes/{pass}/revoke',
                summary: 'Отозвать пропуск',
                description: 'Ссылка и код перестают работать сразу. Нужен, если пропуск ушёл не тому или курьер сменился.',
                params: [Param::integer('pass', 'id пропуска из pickup.passes.list', true)],
                handler: [self::class, 'revoke'],
                mutating: true,
                gate: FeatureGate::PICKUP,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function schedule(User $actor, OperationInput $input): array
    {
        return Envelope::data($this->schedule->today(now()));
    }

    /** @return array<string, mixed> */
    public function ready(User $actor, OperationInput $input): array
    {
        $rows = $this->resolver->issuesForUser($actor)->map(function (GoodsIssue $gi) {
            $stage = $this->resolver->issueStage($gi, true, $gi->activeHandover !== null);

            return [
                'goods_issue_id' => $gi->id,
                'stage' => $stage->value,
                'packages_count' => (int) $gi->packages_count,
                'ready_since' => $stage === Stage::READY ? $gi->status_changed_at?->toIso8601String() : null,
                'promised_ready_at' => $stage === Stage::PICKING ? $this->schedule->promisedReadyAt($gi->created_at)?->toIso8601String() : null,
                'orders' => collect($gi->getRelation('pickupOrders'))->map(fn ($o) => [
                    'order_id' => $o->id,
                    'number' => $o->erp_number ?: $o->number,
                ])->values()->all(),
            ];
        });

        return Envelope::data([
            'ready' => $rows->where('stage', Stage::READY->value)->values()->all(),
            'picking' => $rows->where('stage', Stage::PICKING->value)->values()->all(),
        ], ['schedule' => $this->schedule->today(now())]);
    }

    /** @return array<string, mixed> */
    public function passes(User $actor, OperationInput $input): array
    {
        $passes = PickupPass::query()->where('user_id', $actor->id)->usable()->latest()->get();

        return Envelope::data($passes->map(fn (PickupPass $pass) => $this->present($pass))->values()->all(), ['total' => $passes->count()]);
    }

    /** @return array<string, mixed> */
    public function create(User $actor, OperationInput $input): array
    {
        $details = [
            'courier_name' => $input->string('courier_name'),
            'courier_phone' => $input->string('courier_phone'),
            'note' => $input->string('note'),
        ];

        [$pass] = $input->string('scope') === PickupPass::SCOPE_ALL
            ? $this->passes->issueAll($actor, $details, 'api')
            : $this->passes->issueSelected($actor, array_map('intval', $input->array('goods_issue_ids')), $details, 'api');

        return Envelope::data($this->present($pass), ['created' => true]);
    }

    /** @return array<string, mixed> */
    public function revoke(User $actor, OperationInput $input): array
    {
        $pass = PickupPass::query()->where('user_id', $actor->id)->find((int) $input->get('pass'));
        if ($pass === null) {
            throw (new ModelNotFoundException)->setModel(PickupPass::class);
        }

        $this->passes->revoke($pass);

        return Envelope::data(['pass_id' => $pass->id, 'revoked' => true]);
    }

    /** @return array<string, mixed> */
    private function present(PickupPass $pass): array
    {
        $items = $this->passes->contents($pass);

        return [
            'pass_id' => $pass->id,
            'scope' => $pass->scope,
            'code' => $pass->code,
            'url' => $this->passes->urlOf($pass),
            'expires_at' => $pass->expires_at->toIso8601String(),
            'courier_name' => $pass->courier_name,
            'to_issue' => $items->where('can_issue', true)->count(),
            'packages_to_issue' => (int) $items->where('can_issue', true)->sum('packages_count'),
            'items' => $items->map(fn (array $row) => [
                'goods_issue_id' => $row['goods_issue_id'],
                'state' => $row['state'],
                'state_label' => $row['state_label'],
                'packages_count' => $row['packages_count'],
                'orders' => array_map(fn (array $o) => ['order_id' => $o['id'], 'number' => $o['number']], $row['orders']),
            ])->values()->all(),
        ];
    }
}
