import { Badge, Card, Heading, HStack, Table, Text } from '@chakra-ui/react';

const fmt = (iso) => (iso
    ? new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
    : '—');

/**
 * «Склад и выдача» в карточке заказа (эпик pick-00, pick-15): менеджер отвечает клиенту на
 * «курьер говорит, заказа нет» за десять секунд и не звонит на склад. Только чтение — выдачу
 * отмечает и отменяет склад.
 */
export function FulfilmentSection({ fulfilment }) {
    if (!fulfilment || fulfilment.stage === 'none') return null;

    return (
        <Card.Root mb={6}>
            <Card.Header>
                <HStack justify="space-between" wrap="wrap" gap={2}>
                    <Heading size="md">Склад и выдача</Heading>
                    <HStack gap={2}>
                        <Badge colorPalette={fulfilment.color} size="lg">{fulfilment.label}</Badge>
                        <Badge variant="outline" size="lg">{fulfilment.is_pickup ? 'Самовывоз' : 'Доставка'}</Badge>
                    </HStack>
                </HStack>
            </Card.Header>
            <Card.Body>
                {fulfilment.promise && (
                    <Text mb={3} color={fulfilment.promise.is_overdue ? 'fg.error' : 'fg.muted'}>
                        {fulfilment.promise.text}{fulfilment.promise.is_overdue ? ' — склад опаздывает' : ''}
                    </Text>
                )}
                {fulfilment.goods_issues.length === 0 ? (
                    <Text color="fg.muted">Расходный ордер ещё не создан: склад заказ не начинал.</Text>
                ) : (
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Расходный ордер</Table.ColumnHeader>
                                <Table.ColumnHeader>Статус 1С</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Мест</Table.ColumnHeader>
                                <Table.ColumnHeader>Собран</Table.ColumnHeader>
                                <Table.ColumnHeader>Выдача</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {fulfilment.goods_issues.map((gi) => (
                                <Table.Row key={gi.id}>
                                    <Table.Cell>
                                        {gi.number}
                                        {gi.shared_with_orders > 0 && <Text as="span" color="fg.muted"> · ещё заказов: {gi.shared_with_orders}</Text>}
                                    </Table.Cell>
                                    <Table.Cell>{gi.status_label}</Table.Cell>
                                    <Table.Cell textAlign="end">{gi.packages_count}</Table.Cell>
                                    <Table.Cell>{gi.ready_since ? `ждёт с ${fmt(gi.ready_since)}` : gi.stage_label}</Table.Cell>
                                    <Table.Cell>
                                        {gi.handed_at
                                            ? `${fmt(gi.handed_at)} · ${gi.handed_by || 'склад'}${gi.recipient_name ? ` · курьер: ${gi.recipient_name}` : ''}`
                                            : '—'}
                                        {gi.needs_review && <Badge ml={2} colorPalette="orange">разбор со складом</Badge>}
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                )}
            </Card.Body>
        </Card.Root>
    );
}
