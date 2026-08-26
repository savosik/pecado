import { router, Link } from '@inertiajs/react';
import {
    Box,
    Card,
    Heading,
    HStack,
    Text,
    Badge,
    Table,
    Button,
} from '@chakra-ui/react';
import { LuSend } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import { useState } from 'react';
import { toaster } from '@/components/ui/toaster';

/**
 * Блок «Отправка поставщику» на карточке предзаказа.
 *
 * Показывает историю попыток отправки заказа поставщику (Customer API sex-opt.ru)
 * с ответами и позволяет переотправить заказ вручную — автоматических ретраев нет.
 */
const STATUS_PALETTE = {
    success: 'green',
    testmode: 'blue',
    rollback: 'orange',
    failed: 'red',
};

export const SupplierPreorderSection = ({ orderId, panel }) => {
    const [sending, setSending] = useState(false);

    const handleSend = () => {
        setSending(true);
        router.post(route('admin.supplier-preorders.send', orderId), {}, {
            preserveScroll: true,
            onFinish: () => setSending(false),
            onSuccess: () => toaster.create({
                description: 'Отправка предзаказа поставщику поставлена в очередь',
                type: 'success',
            }),
            onError: () => toaster.create({
                description: 'Не удалось поставить отправку в очередь',
                type: 'error',
            }),
        });
    };

    return (
        <Card.Root>
            <Card.Header>
                <HStack justify="space-between" wrap="wrap" gap={3}>
                    <HStack gap={3} wrap="wrap">
                        <Heading size="md">Отправка поставщику</Heading>
                        <Badge colorPalette={panel.enabled ? 'green' : 'gray'} variant="subtle">
                            {panel.enabled ? 'Включена' : 'Выключена'}
                        </Badge>
                        <Badge colorPalette="purple" variant="subtle">Склад: {panel.stock}</Badge>
                        {panel.testmode && (
                            <Badge colorPalette="blue" variant="subtle">Тестовый режим</Badge>
                        )}
                    </HStack>
                    {panel.can_send && panel.enabled && (
                        <Button size="sm" colorPalette="blue" loading={sending} onClick={handleSend}>
                            <LuSend /> {panel.requests.length > 0 ? 'Отправить повторно' : 'Отправить поставщику'}
                        </Button>
                    )}
                </HStack>
            </Card.Header>
            <Card.Body p={panel.requests.length > 0 ? 0 : undefined}>
                {panel.requests.length === 0 ? (
                    <Text color="fg.muted" fontSize="sm">
                        Предзаказ поставщику ещё не отправлялся
                    </Text>
                ) : (
                    <Box overflowX="auto">
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Попытка</Table.ColumnHeader>
                                    <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                    <Table.ColumnHeader>Комментарий</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Позиций</Table.ColumnHeader>
                                    <Table.ColumnHeader>Заказ у поставщика</Table.ColumnHeader>
                                    <Table.ColumnHeader>Инициатор</Table.ColumnHeader>
                                    <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                    <Table.ColumnHeader></Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {panel.requests.map((item) => (
                                    <Table.Row key={item.id}>
                                        <Table.Cell>{item.attempt}</Table.Cell>
                                        <Table.Cell>
                                            <Badge colorPalette={STATUS_PALETTE[item.status] ?? 'red'} variant="subtle">
                                                {item.status_label}
                                            </Badge>
                                            {item.error_message && (
                                                <Text fontSize="xs" color="red.500" truncate maxW="260px" title={item.error_message}>
                                                    {item.error_message}
                                                </Text>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontSize="xs" color="fg.muted" truncate maxW="260px" title={item.comment}>
                                                {item.comment}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <Text fontFamily="mono">{item.items_count}</Text>
                                            {item.skipped_count > 0 && (
                                                <Text fontSize="xs" color="orange.500">
                                                    без кода: {item.skipped_count}
                                                </Text>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontFamily="mono">{item.supplier_order_id || '—'}</Text>
                                        </Table.Cell>
                                        <Table.Cell>{item.triggered_by || 'Автоматически'}</Table.Cell>
                                        <Table.Cell>
                                            <Text fontSize="sm" color="fg.muted">{item.created_at}</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <RowActions size="xs" view={{ href: route('admin.supplier-preorders.show', item.id) }} />
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                )}
            </Card.Body>
        </Card.Root>
    );
};
