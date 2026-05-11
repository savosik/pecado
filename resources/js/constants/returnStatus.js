// Справочник статусов заявки на возврат (v15) — соответствует
// App\Enums\ReturnStatus и перечислению 1С:КА2
// (docs-erp/content/rules/returns.md).

export const RETURN_STATUS_LABELS = {
    pending_approval: 'На согласовании',
    for_return: 'К возврату',
    in_reserve: 'В резерве',
    ready_for_shipment: 'К отгрузке',
    completed: 'Выполнена',
    rejected: 'Отклонена',
};

export const RETURN_STATUS_COLORS = {
    pending_approval: 'yellow',
    for_return: 'orange',
    in_reserve: 'blue',
    ready_for_shipment: 'purple',
    completed: 'green',
    rejected: 'red',
};

export const RETURN_STATUS_DEFAULT = 'pending_approval';

export const getReturnStatusLabel = (status) =>
    RETURN_STATUS_LABELS[status] ?? status ?? '—';

export const getReturnStatusColor = (status) =>
    RETURN_STATUS_COLORS[status] ?? 'gray';
