import { router, Link, Head } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box,
    Text,
    Badge,
    HStack,
    VStack,
    Card,
    Heading,
    SimpleGrid,
    IconButton,
    Button,
    Table,
} from '@chakra-ui/react';
import { LuArrowLeft, LuSend } from 'react-icons/lu';
import { useState } from 'react';
import { toaster } from '@/components/ui/toaster';
import { STATUS_META } from './Index';

const Json = ({ value }) => (
    <Box
        as="pre"
        p={3}
        borderRadius="md"
        bg="bg.muted"
        fontSize="xs"
        fontFamily="mono"
        overflowX="auto"
        whiteSpace="pre-wrap"
    >
        {JSON.stringify(value, null, 2)}
    </Box>
);

const Row = ({ label, children }) => (
    <HStack justify="space-between" align="start" gap={4}>
        <Text color="fg.muted" flexShrink={0}>{label}:</Text>
        <Box textAlign="right">{children}</Box>
    </HStack>
);

export default function SupplierPreorderShow({ request, settings, can }) {
    const [sending, setSending] = useState(false);
    const meta = STATUS_META[request.status] ?? STATUS_META.failed;

    const handleResend = () => {
        setSending(true);
        router.post(route('admin.supplier-preorders.send', request.order_id), {}, {
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

    const shortage = request.warnings?.shortage ?? {};
    const unknownItems = request.warnings?.unknown_items ?? [];

    return (
        <>
            <Head title={`Отправка предзаказа №${request.id}`} />

            <HStack justify="space-between" mb={6}>
                <HStack gap={3}>
                    <Link href={route('admin.supplier-preorders.index')}>
                        <IconButton size="sm" variant="ghost" aria-label="Назад">
                            <LuArrowLeft />
                        </IconButton>
                    </Link>
                    <PageHeader title={`Отправка предзаказа №${request.id}`} />
                </HStack>
                {can.send && settings.enabled && (
                    <Button size="sm" colorPalette="blue" loading={sending} onClick={handleResend}>
                        <LuSend /> Отправить повторно
                    </Button>
                )}
            </HStack>

            <SimpleGrid columns={{ base: 1, lg: 2 }} gap={6} mb={6}>
                <Card.Root>
                    <Card.Header>
                        <Heading size="md">Отправка</Heading>
                    </Card.Header>
                    <Card.Body>
                        <VStack align="stretch" gap={3}>
                            <Row label="Статус">
                                <Badge colorPalette={meta.palette} variant="subtle">
                                    {request.status_label}
                                </Badge>
                            </Row>
                            <Row label="Предзаказ">
                                <Link href={route('admin.orders.show', request.order_id)}>
                                    <Text fontFamily="mono" color="blue.500">
                                        {request.order_number || `#${request.order_id}`}
                                    </Text>
                                </Link>
                            </Row>
                            <Row label="Попытка">
                                <Text>{request.attempt}</Text>
                            </Row>
                            <Row label="Склад поставщика">
                                <Text>{request.stock}</Text>
                            </Row>
                            <Row label="Режим">
                                <Text>{request.testmode ? 'Тестовый (transaction=rollback)' : 'Боевой'}</Text>
                            </Row>
                            <Row label="Комментарий">
                                <Text>{request.comment || '—'}</Text>
                            </Row>
                            <Row label="Позиций отправлено">
                                <Text fontFamily="mono">{request.items_count}</Text>
                            </Row>
                            <Row label="Инициатор">
                                <Text>{request.triggered_by || 'Автоматически'}</Text>
                            </Row>
                            <Row label="Дата">
                                <Text>{request.created_at}</Text>
                            </Row>
                        </VStack>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header>
                        <Heading size="md">Ответ поставщика</Heading>
                    </Card.Header>
                    <Card.Body>
                        <VStack align="stretch" gap={3}>
                            <Row label="HTTP-код">
                                <Text fontFamily="mono">{request.http_status ?? '—'}</Text>
                            </Row>
                            <Row label="Заказ у поставщика">
                                <Text fontFamily="mono">{request.supplier_order_id || '—'}</Text>
                            </Row>
                            <Row label="Длительность">
                                <Text fontFamily="mono">
                                    {request.duration_ms != null ? `${request.duration_ms} мс` : '—'}
                                </Text>
                            </Row>
                            {request.error_message && (
                                <Box>
                                    <Text color="fg.muted" mb={1}>Ошибка:</Text>
                                    <Text color="red.500" fontSize="sm">{request.error_message}</Text>
                                </Box>
                            )}
                            {unknownItems.length > 0 && (
                                <Box>
                                    <Text color="fg.muted" mb={1}>Неизвестные поставщику коды:</Text>
                                    <Text fontFamily="mono" fontSize="sm" color="orange.500">
                                        {unknownItems.join(', ')}
                                    </Text>
                                </Box>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>
            </SimpleGrid>

            {Object.keys(shortage).length > 0 && (
                <Card.Root mb={6}>
                    <Card.Header>
                        <Heading size="md">Нехватка на складе поставщика</Heading>
                    </Card.Header>
                    <Card.Body p={0}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Код товара</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Нужно</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Есть</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Не хватает</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {Object.entries(shortage).map(([code, info]) => (
                                    <Table.Row key={code}>
                                        <Table.Cell fontFamily="mono">{code}</Table.Cell>
                                        <Table.Cell textAlign="right">{info.needed}</Table.Cell>
                                        <Table.Cell textAlign="right">{info.in_stock}</Table.Cell>
                                        <Table.Cell textAlign="right" color="orange.500">{info.shortage}</Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Card.Body>
                </Card.Root>
            )}

            {request.skipped_items?.length > 0 && (
                <Card.Root mb={6}>
                    <Card.Header>
                        <Heading size="md">Позиции без кода 1С (не отправлены)</Heading>
                    </Card.Header>
                    <Card.Body p={0}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {request.skipped_items.map((item, i) => (
                                    <Table.Row key={i}>
                                        <Table.Cell>{item.name}</Table.Cell>
                                        <Table.Cell textAlign="right">{item.quantity}</Table.Cell>
                                        <Table.Cell>{item.reason}</Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Card.Body>
                </Card.Root>
            )}

            <SimpleGrid columns={{ base: 1, lg: 2 }} gap={6}>
                <Card.Root>
                    <Card.Header>
                        <Heading size="md">Запрос</Heading>
                    </Card.Header>
                    <Card.Body>
                        <Json value={request.request_payload} />
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header>
                        <Heading size="md">Ответ (JSON)</Heading>
                    </Card.Header>
                    <Card.Body>
                        {request.response_payload ? (
                            <Json value={request.response_payload} />
                        ) : request.response_raw ? (
                            <Box as="pre" p={3} borderRadius="md" bg="bg.muted" fontSize="xs" overflowX="auto" whiteSpace="pre-wrap">
                                {request.response_raw}
                            </Box>
                        ) : (
                            <Text color="fg.muted" fontSize="sm">Ответа нет</Text>
                        )}
                    </Card.Body>
                </Card.Root>
            </SimpleGrid>
        </>
    );
}

SupplierPreorderShow.layout = (page) => <AdminLayout>{page}</AdminLayout>;
