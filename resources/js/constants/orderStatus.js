// Справочник статусов заказа (v14) — соответствует App\Enums\OrderStatus
// и перечислению 1С:КА2 (docs-erp/content/rules/orders.md).

export const ORDER_STATUS_LABELS = {
    pending_approval: 'Ожидается согласование',
    pending_payment_before_provision: 'Ожидается оплата до обеспечения',
    ready_for_provision: 'Готов к обеспечению',
    pending_payment_before_shipment: 'Ожидается оплата до отгрузки',
    awaiting_provision: 'Ожидается обеспечение',
    ready_for_shipment: 'Готов к отгрузке',
    shipping: 'В процессе отгрузки',
    awaiting_payment: 'Ожидается оплата',
    ready_for_closure: 'Готов к закрытию',
    closed: 'Закрыт',
};

export const ORDER_STATUS_COLORS = {
    pending_approval: 'yellow',
    pending_payment_before_provision: 'orange',
    ready_for_provision: 'cyan',
    pending_payment_before_shipment: 'orange',
    awaiting_provision: 'blue',
    ready_for_shipment: 'purple',
    shipping: 'teal',
    awaiting_payment: 'orange',
    ready_for_closure: 'green',
    closed: 'gray',
};

export const ORDER_STATUS_DEFAULT = 'pending_approval';

export const getOrderStatusLabel = (status) =>
    ORDER_STATUS_LABELS[status] ?? status ?? '—';

export const getOrderStatusColor = (status) =>
    ORDER_STATUS_COLORS[status] ?? 'gray';
