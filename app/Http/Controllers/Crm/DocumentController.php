<?php

namespace App\Http\Controllers\Crm;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\Crm\CrmEntityResolver;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Карточки заказа и реализации внутри CRM.
 *
 * Раньше из ленты и из списка документов ссылка вела в админку — куда роли
 * `sales-head` и `sales-manager-crm` намеренно не пускают. Менеджер видел
 * ссылку и упирался в 403, а РОП не мог посмотреть состав заказа вообще.
 *
 * Отдельного права нет: документ клиента — часть его карточки, и «вижу клиента,
 * но не вижу его заказы» — состояние, которого быть не должно. Доступ решает
 * тот же скоуп, что и везде, через CrmEntityResolver: чужой документ даёт 404.
 *
 * Читаем только — заказы и реализации принадлежат 1С, редактирование живёт
 * в админке и там же остаётся.
 */
class DocumentController extends CrmController
{
    public function __construct(private readonly CrmEntityResolver $resolver) {}

    public function order(Request $request, int $order): Response
    {
        $actor = $this->crmActor($request);

        /** @var Order $model */
        $model = $this->resolver->resolveForActor($actor, CrmEntityMap::ORDER, $order);

        $model->load([
            'user:id,name,email,phone,personal_manager_id',
            'company:id,name,tax_id',
            // is_stub обязателен в выборке: без него незаведённое юрлицо
            // показалось бы менеджеру голым UUID-ом вместо названия.
            'organization:id,name,is_stub',
            'warehouse:id,name',
            'items.product:id,name,slug,sku',
            'shipments' => fn ($query) => $query->orderByDesc('date'),
        ]);

        return Inertia::render('Crm/Pages/Documents/Show', [
            'document' => [
                'type' => CrmEntityMap::ORDER,
                'id' => (int) $model->getKey(),
                'title' => 'Заказ №'.($model->erp_number ?: $model->number ?: $model->getKey()),
                'number' => $model->number,
                'erp_number' => $model->erp_number,
                'status_label' => $model->status->label(),
                'status_color' => $model->status->color(),
                'date_label' => ($model->erp_created_at ?? $model->created_at)?->format('d.m.Y H:i'),
                'created_at_label' => $model->created_at?->format('d.m.Y H:i'),
                'total_label' => $this->money((float) $model->total_amount, $model->currency_code),
                'comment' => $model->comment,
                'manager_comment' => $model->manager_comment,
                'delivery_address' => $model->delivery_address,
                'organization' => $this->organization($model),
                'warehouse' => $this->warehouse($model),
                'company' => $model->company?->getAttribute('name'),
                'admin_url' => $actor->hasAdminAccess() ? route('admin.orders.show', $model->getKey()) : null,
                'items' => $model->items->map(fn (OrderItem $item): array => [
                    'id' => (int) $item->getKey(),
                    'name' => $item->name ?: $item->product?->name ?: 'Позиция №'.$item->getKey(),
                    'sku' => $item->product?->sku,
                    'brand' => $item->brand_name_snapshot,
                    'quantity' => (int) $item->quantity,
                    'price_label' => $this->money((float) $item->final_price, $model->currency_code),
                    'total_label' => $this->money((float) $item->subtotal, $model->currency_code),
                ])->all(),
                'related' => $model->shipments->map(fn (Shipment $shipment): array => [
                    'type' => CrmEntityMap::SHIPMENT,
                    'id' => (int) $shipment->getKey(),
                    'title' => 'Реализация №'.($shipment->erp_number ?: $shipment->number ?: $shipment->getKey()),
                    'date_label' => ($shipment->erp_created_at ?? $shipment->date)?->format('d.m.Y'),
                    'total_label' => $this->money((float) $shipment->total_amount, $shipment->currency_code),
                    'url' => route('crm.shipments.show', $shipment->getKey()),
                ])->all(),
            ],
            'client' => $this->clientPayload($model->user),
        ]);
    }

    public function shipment(Request $request, int $shipment): Response
    {
        $actor = $this->crmActor($request);

        /** @var Shipment $model */
        $model = $this->resolver->resolveForActor($actor, CrmEntityMap::SHIPMENT, $shipment);

        $model->load([
            'user:id,name,email,phone,personal_manager_id',
            'company:id,name,tax_id',
            // is_stub обязателен в выборке: без него незаведённое юрлицо
            // показалось бы менеджеру голым UUID-ом вместо названия.
            'organization:id,name,is_stub',
            'warehouse:id,name',
            'items.product:id,name,slug,sku',
        ]);

        // Заказы, по которым сделана отгрузка: связь идёт через order_uuid
        // в позициях, а не прямой ссылкой на заказ — одна отгрузка может
        // закрывать несколько заказов.
        $related = [];

        foreach ($model->getRelatedOrders() as $order) {
            /** @var Order $order */
            $related[] = [
                'type' => CrmEntityMap::ORDER,
                'id' => (int) $order->getKey(),
                'title' => 'Заказ №'.($order->erp_number ?: $order->number ?: $order->getKey()),
                'date_label' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y'),
                'total_label' => $this->money((float) $order->total_amount, $order->currency_code),
                'url' => route('crm.orders.show', $order->getKey()),
            ];
        }

        return Inertia::render('Crm/Pages/Documents/Show', [
            'document' => [
                'type' => CrmEntityMap::SHIPMENT,
                'id' => (int) $model->getKey(),
                'title' => 'Реализация №'.($model->erp_number ?: $model->number ?: $model->getKey()),
                'number' => $model->number,
                'erp_number' => $model->erp_number,
                'status_label' => $model->status_label,
                'status_color' => $model->status === 'completed' ? 'green' : 'blue',
                'date_label' => ($model->erp_created_at ?? $model->date)?->format('d.m.Y H:i'),
                'created_at_label' => $model->created_at?->format('d.m.Y H:i'),
                'total_label' => $this->money((float) $model->total_amount, $model->currency_code),
                'comment' => null,
                'manager_comment' => null,
                'delivery_address' => null,
                'organization' => $this->organization($model),
                'warehouse' => $this->warehouse($model),
                'company' => $model->company?->getAttribute('name'),
                'admin_url' => $actor->hasAdminAccess() ? route('admin.shipments.show', $model->getKey()) : null,
                'items' => $model->items->map(fn (ShipmentItem $item): array => [
                    'id' => (int) $item->getKey(),
                    'name' => $item->product_name_snapshot ?: $item->product?->name ?: 'Позиция №'.$item->getKey(),
                    'sku' => $item->product?->sku,
                    'brand' => $item->brand_name_snapshot,
                    'quantity' => (int) $item->quantity,
                    'price_label' => $this->money((float) $item->price, $model->currency_code),
                    'total_label' => $this->money((float) $item->total, $model->currency_code),
                ])->all(),
                'related' => $related,
            ],
            'client' => $this->clientPayload($model->user),
        ]);
    }

    /**
     * Наша организация документа — юрлицо, на которое 1С его провела.
     *
     * Показ гейтит `ORGANIZATIONS_ENABLED`, ровно как в админке и ЛК: приём из 1С
     * работает всегда, а витрина включается, когда справочник заполнен.
     *
     * @param  Order|Shipment  $model
     * @return array{name: string, is_stub: bool}|null
     */
    private function organization(\Illuminate\Database\Eloquent\Model $model): ?array
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $organization = $model->getRelationValue('organization');

        if (! $organization instanceof \Illuminate\Database\Eloquent\Model) {
            return null;
        }

        return [
            'name' => (string) $organization->getAttribute('name'),
            'is_stub' => (bool) $organization->getAttribute('is_stub'),
        ];
    }

    /**
     * Склад отгрузки документа — определён 1С, сайт только показывает.
     *
     * @param  Order|Shipment  $model
     */
    private function warehouse(\Illuminate\Database\Eloquent\Model $model): ?string
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $warehouse = $model->getRelationValue('warehouse');

        return $warehouse instanceof \Illuminate\Database\Eloquent\Model
            ? (string) $warehouse->getAttribute('name')
            : null;
    }

    /**
     * Клиент документа — шапка карточки и ссылка обратно в его ленту.
     *
     * @return array<string, mixed>|null
     */
    private function clientPayload(?\App\Models\User $client): ?array
    {
        if ($client === null) {
            return null;
        }

        return [
            'id' => (int) $client->getKey(),
            'name' => $client->name,
            'email' => $client->email,
            'phone' => $client->phone,
            'url' => route('crm.clients.show', $client->getKey()),
        ];
    }

    /**
     * Сумма с валютой — форматируется на сервере, как и даты.
     */
    private function money(float $amount, ?string $currencyCode): string
    {
        $symbol = match ($currencyCode) {
            'RUB', null => '₽',
            'KZT' => '₸',
            'BYN' => 'Br',
            default => $currencyCode,
        };

        return number_format($amount, 2, ',', ' ').' '.$symbol;
    }
}
