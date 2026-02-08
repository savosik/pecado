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
        processing: "blue",
        shipped: "purple",
        delivered: "green",
        cancelled: "red",
    };
    return colors[status] || "gray";
};

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
            <Head title={`Заказ #${order.id}`} />

            <PageHeader
                title={`Заказ #${order.id}`}
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
                                <Text color="fg.muted">UUID:</Text>
                                <Text fontFamily="mono" fontSize="sm">{order.uuid}</Text>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Статус:</Text>
                                <Badge colorPalette={getStatusColor(order.status)}>
                                    {order.status_label}
                                </Badge>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Сумма:</Text>
                                <Text fontWeight="bold">{order.total_amount} {order.currency_code || "₽"}</Text>
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
                                        <Text>{order.user.full_name}</Text>
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
                                    <Text>{order.delivery_address.name}</Text>
                                    <Text color="fg.muted" fontSize="sm">{order.delivery_address.address}</Text>
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
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Цена</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Кол-во</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {order.items.map((item) => (
                                <Table.Row key={item.id}>
                                    <Table.Cell>
                                        <Text>{item.name}</Text>
                                        {item.product && (
                                            <Text color="fg.muted" fontSize="xs">
                                                ID: {item.product.id}
                                            </Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right">{item.price} ₽</Table.Cell>
                                    <Table.Cell textAlign="right">{item.quantity}</Table.Cell>
                                    <Table.Cell textAlign="right" fontWeight="medium">{item.subtotal} ₽</Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Card.Body>
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
