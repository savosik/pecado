import React from "react";
import {
    Box,
    Card,
    HStack,
    VStack,
    Text,
    Badge,
    Table,
    Heading,
    SimpleGrid,
    Button,
} from "@chakra-ui/react";
import { Head, usePage, router } from "@inertiajs/react";
import { LuArrowLeft, LuPencil } from "react-icons/lu";
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from "@/Admin/Components/PageHeader";
import { Field } from "@/components/ui/field";
import { toaster } from "@/components/ui/toaster";
import { StatusHistoryTimeline } from "./Components/StatusHistoryTimeline";

const getStatusColor = (status) => {
    const colors = {
        pending: "yellow",
        confirmed: "blue",
        ready_to_ship: "purple",
        closed: "green",
        deleted: "red",
    };
    return colors[status] || "gray";
};

const getTypeLabel = (type) => type === 'preorder' ? 'Предзаказ' : 'Заказ со склада';
const getTypeColor = (type) => type === 'preorder' ? 'purple' : 'teal';

const fmt = (v) =>
    parseFloat(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const OrderShow = () => {
    const { order, statuses } = usePage().props;

    const handleStatusChange = (newStatus) => {
        router.patch(route("admin.orders.status", order.id), {
            status: newStatus,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({
                    description: "Статус заказа обновлён",
                    type: "success",
                });
            },
        });
    };

    return (
        <>
            <Head title={`Заказ ${order.number || ("#" + order.id)}`} />

            <PageHeader
                title={
                    <HStack gap={2}>
                        <span>{`Заказ ${order.number || ("#" + order.id)}`}</span>
                        <Badge colorPalette={getTypeColor(order.type)} variant="subtle" fontSize="sm">
                            {getTypeLabel(order.type)}
                        </Badge>
                    </HStack>
                }
                actions={
                    <HStack>
                        <Button
                            variant="ghost"
                            onClick={() => router.visit(route("admin.orders.index"))}
                        >
                            <LuArrowLeft /> Назад к списку
                        </Button>
                        <Button
                            onClick={() => router.visit(route("admin.orders.edit", order.id))}
                            colorPalette="blue"
                        >
                            <LuPencil /> Редактировать
                        </Button>
                    </HStack>
                }
            />

            <SimpleGrid columns={{ base: 1, lg: 2 }} gap={6} mb={6}>
                {/* Основная информация */}
                <Card.Root>
                    <Card.Header>
                        <Heading size="md">Информация о заказе</Heading>
                    </Card.Header>
                    <Card.Body>
                        <VStack align="stretch" gap={4}>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Номер:</Text>
                                <Text fontFamily="mono" fontSize="sm">{order.number || ("#" + order.id)}</Text>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">UUID:</Text>
                                <Text fontFamily="mono" fontSize="sm">{order.uuid}</Text>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Тип:</Text>
                                <Badge colorPalette={getTypeColor(order.type)} variant="subtle">
                                    {getTypeLabel(order.type)}
                                </Badge>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Статус:</Text>
                                <Badge colorPalette={getStatusColor(order.status)}>
                                    {order.status_label}
                                </Badge>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Сумма:</Text>
                                <Text fontWeight="bold">{fmt(order.total_amount)} {order.currency_code || "₽"}</Text>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Дата создания:</Text>
                                <Text>{order.created_at}</Text>
                            </HStack>
                            {order.comment && (
                                <Box>
                                    <Text color="fg.muted" mb={1}>Комментарий:</Text>
                                    <Text>{order.comment}</Text>
                                </Box>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>

                {/* Информация о клиенте */}
                <Card.Root>
                    <Card.Header>
                        <Heading size="md">Клиент</Heading>
                    </Card.Header>
                    <Card.Body>
                        <VStack align="stretch" gap={4}>
                            {order.user ? (
                                <>
                                    <HStack justify="space-between">
                                        <Text color="fg.muted">Имя:</Text>
                                        <Text>{order.user.name}</Text>
                                    </HStack>
                                    <HStack justify="space-between">
                                        <Text color="fg.muted">Email:</Text>
                                        <Text>{order.user.email}</Text>
                                    </HStack>
                                    {order.user.phone && (
                                        <HStack justify="space-between">
                                            <Text color="fg.muted">Телефон:</Text>
                                            <Text>{order.user.phone}</Text>
                                        </HStack>
                                    )}
                                </>
                            ) : (
                                <Text color="fg.muted">Данные клиента недоступны</Text>
                            )}

                            {order.company && (
                                <>
                                    <Box borderTopWidth="1px" pt={3}>
                                        <Text fontWeight="medium" mb={2}>Компания</Text>
                                        <Text>{order.company.name}</Text>
                                    </Box>
                                </>
                            )}

                            {order.delivery_address && (
                                <Box borderTopWidth="1px" pt={3}>
                                    <Text fontWeight="medium" mb={2}>Адрес доставки</Text>
                                    <Text>{order.delivery_address}</Text>
                                </Box>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>
            </SimpleGrid>

            {/* Изменение статуса */}
            <Card.Root mb={6}>
                <Card.Header>
                    <Heading size="md">Изменить статус</Heading>
                </Card.Header>
                <Card.Body>
                    <HStack gap={2} wrap="wrap">
                        {statuses.map((status) => (
                            <Button
                                key={status.value}
                                size="sm"
                                colorPalette={getStatusColor(status.value)}
                                variant={order.status === status.value ? "solid" : "outline"}
                                onClick={() => handleStatusChange(status.value)}
                                disabled={order.status === status.value}
                            >
                                {status.label}
                            </Button>
                        ))}
                    </HStack>
                </Card.Body>
            </Card.Root>

            {/* Позиции заказа */}
            <Card.Root>
                <Card.Header>
                    <Heading size="md">Позиции заказа ({order.items.length})</Heading>
                </Card.Header>
                <Card.Body p={0}>
                    <Box overflowX="auto">
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Баз. цена</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Инд. цена</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Скидка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {order.items.map((item) => {
                                    const hasDiscount = parseFloat(item.discount_percent) > 0;
                                    const basePrice = parseFloat(item.base_price) || parseFloat(item.price) || 0;
                                    const finalPrice = parseFloat(item.final_price) || parseFloat(item.price) || 0;

                                    return (
                                        <Table.Row key={item.id}>
                                            <Table.Cell>
                                                <Text>{item.name}</Text>
                                                {item.product && (
                                                    <Text color="fg.muted" fontSize="xs">
                                                        ID: {item.product.id}
                                                    </Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono" color="fg.muted">{fmt(basePrice)} ₽</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono" fontWeight="medium">{fmt(finalPrice)} ₽</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                {hasDiscount ? (
                                                    <Badge colorPalette="green" variant="subtle">
                                                        −{parseFloat(item.discount_percent).toFixed(1)}%
                                                    </Badge>
                                                ) : (
                                                    <Text fontFamily="mono" color="fg.muted">0%</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono">{item.quantity}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono" fontWeight="bold">{fmt(item.subtotal)} ₽</Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    );
                                })}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Card.Body>
                <Card.Footer>
                    <HStack justify="flex-end" width="100%">
                        <Text fontSize="lg" fontWeight="bold">
                            Итого: {fmt(order.total_amount)} {order.currency_code || "₽"}
                        </Text>
                    </HStack>
                </Card.Footer>
            </Card.Root>

            {/* История статусов */}
            <Box mt={6}>
                <StatusHistoryTimeline histories={order.status_histories || []} />
            </Box>
        </>
    );
};

OrderShow.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default OrderShow;
