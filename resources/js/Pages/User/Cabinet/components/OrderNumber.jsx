import { Text } from '@chakra-ui/react';

/**
 * Номер заказа глазами клиента.
 *
 * Пока 1С не присвоила номер, бэкенд отдаёт `number: null` и `number_hint`
 * («будет присвоен при передаче в учётную систему, обычно в течение ~N мин»).
 * Временный сайтовый ORD-… клиенту не показываем: в 1С его нет, и менеджер
 * по нему ничего не найдёт.
 */

const DEFAULT_HINT = 'Номер будет присвоен при передаче в учётную систему.';

/** Дата заказа без времени — для подписи «Заказ от 18.09.2026». */
function orderDate(order) {
    const raw = order?.created_at_formatted || order?.erp_created_at || order?.created_at || '';
    return String(raw).split(' ')[0];
}

/** «Заказ 29УТ-003413» либо «Заказ от 18.09.2026» — для заголовков и диалогов. */
export function orderTitle(order, prefix = 'Заказ') {
    if (order?.number) return `${prefix} ${order.number}`;
    const date = orderDate(order);
    return date ? `${prefix} от ${date}` : prefix;
}

export function orderNumberHint(order) {
    return order?.number_hint || DEFAULT_HINT;
}

export default function OrderNumber({ order, fontSize = 'lg', ...props }) {
    if (order?.number) {
        return (
            <Text
                fontWeight="700"
                fontSize={fontSize}
                fontFamily="mono"
                whiteSpace="nowrap"
                color="gray.800"
                _dark={{ color: 'gray.100' }}
                {...props}
            >
                {order.number}
            </Text>
        );
    }

    return (
        <Text
            fontWeight="600"
            fontSize={fontSize === 'lg' ? 'md' : 'sm'}
            color="fg.muted"
            title={orderNumberHint(order)}
            {...props}
        >
            {orderTitle(order)}
            <Text as="span" fontWeight="400" fontSize="sm" display="block">
                {orderNumberHint(order)}
            </Text>
        </Text>
    );
}
