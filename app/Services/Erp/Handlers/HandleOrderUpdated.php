<?php

namespace App\Services\Erp\Handlers;

use App\Models\Order;
use App\Services\Erp\ErpHandlerOutcome;
use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Erp\Support\LinksPrepaymentToOrder;
use App\Services\Erp\Support\OrderItemsSynchronizer;
use App\Services\Erp\Support\OrderStatusMapper;
use App\Services\Erp\Support\ResolvesDocumentOrganization;
use App\Services\Order\OrderChangeLogger;
use Illuminate\Support\Facades\Log;

/**
 * US-08 v11: Обработка события order.updated из 1С.
 *
 * Обновляет статус заказа и позиции по UUID.
 * v11: Записывает журнал изменений (diff) для прозрачности клиенту.
 * v12.1: Обновляет адрес доставки, если передан delivery_address.
 * v13: Логирует также атрибутные изменения (адрес, комментарий и т.д.).
 * v15.4: Если заказа нет — восстанавливает его из payload (1С теряет order.created).
 */
class HandleOrderUpdated
{
    use LinksPrepaymentToOrder;
    use ResolvesDocumentOrganization;

    public function __construct(
        private readonly OrderChangeLogger $changeLogger,
        private readonly HandleOrderCreated $orderCreated,
        private readonly ErpHandlerOutcome $outcome,
        private readonly OrderItemsSynchronizer $itemsSynchronizer,
    ) {}

    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandleOrderUpdated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        // withTrashed: если заказ был soft-deleted (предыдущий order.updated
        // пришёл со статусом «Удалён»), 1С может вернуть его «к жизни»
        // повторным order.updated с другим статусом.
        $order = Order::withTrashed()->where('uuid', $uuid)->first();

        if (! $order) {
            $this->recoverMissingOrder($payload, $uuid);

            return;
        }

        $order->load(['company', 'user', 'organization']);
        $oldAttrs = $this->changeLogger->snapshotAttributes($order);

        // Если 1С прислала статус «Удалён» — soft-delete + status='closed'.
        $shouldSoftDelete = false;
        $shouldRestore = false;

        // Обновление статуса
        if (isset($payload['status'])) {
            $rawStatus = $payload['status'];

            $shouldSoftDelete = OrderStatusMapper::isDeletedMarker($rawStatus);
            $mappedStatus = OrderStatusMapper::toCanonical($rawStatus);

            if ($mappedStatus === null) {
                Log::warning('HandleOrderUpdated: нераспознанный статус из 1С, игнорирую', [
                    'uuid' => $uuid,
                    'status_raw' => $rawStatus,
                ]);
            } else {
                if ($order->trashed() && ! $shouldSoftDelete) {
                    $shouldRestore = true;
                }
                $order->status = $mappedStatus;
            }
        }

        // Обновление номера из 1С (v12.3)
        if (isset($payload['number'])) {
            $order->erp_number = $payload['number'];
        }

        // Обновление адреса доставки (v12.1)
        if (isset($payload['delivery_address'])) {
            $order->delivery_address = $payload['delivery_address'];
        }

        // v15.3: способ доставки (delivery | pickup).
        // Отсутствие ключа или null — способ доставки не меняется.
        if (! empty($payload['delivery_method'])) {
            $order->delivery_method = $payload['delivery_method'];
        }

        // v13.8: comment — пользовательское поле, не перезаписываем из шины 1С.
        // То, что клиент ввёл при оформлении заказа на сайте, неприкосновенно.

        // v13.7: аудит-метки 1С. array_key_exists, чтобы передача null
        // тоже считалась явной операцией; отсутствие ключа — не трогаем БД.
        // TZ-нормализация — в App\Casts\ErpDatetime.
        if (array_key_exists('erp_created_at', $payload)) {
            $order->erp_created_at = $payload['erp_created_at'];
        }
        if (array_key_exists('erp_updated_at', $payload)) {
            $order->erp_updated_at = $payload['erp_updated_at'];
        }

        // v15.8.0: организация и склад проведения. Отсутствие поля не сбрасывает
        // сохранённое значение; смена организации попадёт в OrderChangeLog ниже —
        // менеджер должен видеть, что документ переехал между юрлицами.
        $organizationFields = $this->resolveOrganizationFields($payload, 'HandleOrderUpdated');
        foreach ($organizationFields as $field => $value) {
            $order->{$field} = $value;
        }

        $this->warnOnAssignedWarehouseMismatch($order, $organizationFields, 'HandleOrderUpdated');

        $order->fromErp = true;

        if ($shouldRestore) {
            $order->restoreQuietly();
        }

        $order->save();

        // soft-delete после save — чтобы статус 'closed' уже был зафиксирован
        if ($shouldSoftDelete && ! $order->trashed()) {
            $order->deleteQuietly();
        }

        // Обновление позиций (если переданы) с журналированием
        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->syncItemsWithHistory($order, $payload['items']);
        }

        // v15.16.0: предоплата по заказу приходит расшифровкой платежа, своей
        // очередью. Если платёж доехал раньше заказа, агрегат на заказе пуст —
        // пересчитываем его от строк расшифровки.
        $this->refreshOrderPrepayment($order);

        // Логируем атрибутные изменения (кроме статуса — он идёт в OrderStatusHistory)
        $order->refresh()->load(['company', 'user', 'organization']);
        $newAttrs = $this->changeLogger->snapshotAttributes($order);
        $this->changeLogger->logAttributeChanges($order, $oldAttrs, $newAttrs, 'erp');

        Log::info('HandleOrderUpdated: заказ обновлён', [
            'uuid' => $uuid,
            'status' => $payload['status'] ?? 'не изменён',
            'delivery_address' => isset($payload['delivery_address']) ? 'обновлён' : 'не изменён',
            'delivery_method' => ! empty($payload['delivery_method']) ? $payload['delivery_method'] : 'не изменён',
        ]);
    }

    /**
     * v15.4: заказа с таким uuid на сайте нет — 1С потеряла order.created.
     *
     * Достраиваем заказ из этого payload по правилам order.created, если данных
     * хватает. Если нет — бросаем ErpUnprocessableMessageException, чтобы сообщение
     * попало в админку как ошибка, а не растворилось (до v15.4 здесь был молчаливый
     * return, из-за которого за 1–15 июля 2026 потерялось 40 заказов).
     *
     * @throws ErpUnprocessableMessageException когда данных для восстановления не хватает
     */
    private function recoverMissingOrder(array $payload, string $uuid): void
    {
        $number = $payload['number'] ?? null;
        $missing = [];

        if (empty($payload['partner_uuid'])) {
            $missing[] = 'partner_uuid';
        }

        if (empty($payload['items'])) {
            $missing[] = 'непустой items';
        }

        if ($missing !== []) {
            throw new ErpUnprocessableMessageException(sprintf(
                'order.updated по несуществующему заказу %s (uuid %s): 1С не прислала order.created, '
                .'а восстановить заказ из этого payload нельзя — нет %s. Заказ нужно завести из 1С повторно.',
                $number ?? 'без номера',
                $uuid,
                implode(' и ', $missing),
            ));
        }

        // event подменяем, чтобы payload соответствовал тому, что обрабатывает
        // HandleOrderCreated. Он делает upsert по uuid и сам создаёт контрагента,
        // позиции и связь с партнёром — дублировать эту логику здесь не нужно.
        $this->orderCreated->handle(array_merge($payload, [
            'event' => 'order.created',
            'contractor' => $this->contractorForRecovery($payload),
        ]));

        Log::warning('HandleOrderUpdated: заказ восстановлен из order.updated — 1С не прислала order.created', [
            'uuid' => $uuid,
            'number' => $number,
            'status' => $payload['status'] ?? null,
            'items_count' => count($payload['items']),
        ]);

        $this->outcome->markRecovered(sprintf(
            'Заказ %s (uuid %s) восстановлен из order.updated: 1С не прислала order.created. '
            .'Проверьте на стороне 1С, почему потерялось событие создания.',
            $number ?? 'без номера',
            $uuid,
        ));
    }

    /**
     * v15.4: контрагент для восстановления заказа.
     *
     * В order.updated 1С шлёт контрагента без названия — только uuid и tax_id.
     * Обычно этого хватает: контрагент уже заведён, и HandleOrderCreated находит
     * его по uuid либо tax_id. Но если это новый контрагент, он будет создан, а
     * companies.name — NOT NULL. Поэтому подставляем название-заглушку: она
     * переживёт лишь до ближайшего contractor.updated из 1С.
     *
     * Заглушка живёт только здесь, на пути восстановления. В штатном order.created
     * 1С обязана присылать название, и подменять его молча нельзя.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function contractorForRecovery(array $payload): array
    {
        $contractor = $payload['contractor'] ?? [];

        if (! is_array($contractor) || $contractor === []) {
            return [];
        }

        if (! empty($contractor['name'])) {
            return $contractor;
        }

        $contractor['name'] = ! empty($contractor['legal_name'])
            ? $contractor['legal_name']
            : sprintf('Контрагент ИНН %s', $contractor['tax_id'] ?? 'неизвестен');

        return $contractor;
    }

    /**
     * Синхронизация позиций с записью истории изменений.
     *
     * v15.16.0: разбор и сопоставление строк вынесены в OrderItemsSynchronizer,
     * общий с order.created. До этого здесь была своя реализация, которая
     * складывала позиции в массив с ключом product_uuid — из-за чего две строки
     * на один товар (недобор) схлопывались в одну, и побеждала последняя.
     */
    private function syncItemsWithHistory(Order $order, array $newItems): void
    {
        $oldSnapshot = $this->changeLogger->snapshotItems($order);
        $oldTotal = (float) $order->total_amount;

        $newTotal = $this->itemsSynchronizer->sync($order, $newItems);

        $order->total_amount = $newTotal;
        $order->saveQuietly();

        $newSnapshot = $this->changeLogger->snapshotItems($order->fresh());

        $this->changeLogger->logItemChanges(
            $order,
            $oldSnapshot,
            $newSnapshot,
            $oldTotal,
            $newTotal,
            'erp',
        );
    }
}
