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
    Textarea,
} from "@chakra-ui/react";
import { Head, usePage, router } from "@inertiajs/react";
import { LuArrowLeft, LuPencil, LuSave } from "react-icons/lu";
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from "@/Admin/Components/PageHeader";
import { toaster } from "@/components/ui/toaster";

const getStatusColor = (status) => {
    const colors = {
        pending: "yellow",
        approved: "green",
        rejected: "red",
        completed: "blue",
    };
    return colors[status] || "gray";
};

const getReasonLabel = (reason) => {
    const labels = {
        defective: "Бракованный товар",
        wrong_item: "Неправильный товар",
        changed_mind: "Передумал",
        damaged_in_transit: "Повреждён при доставке",
        wrong_size: "Неправильный размер",
        other: "Другое",
    };
    return labels[reason] || reason;
};

const ReturnShow = () => {
    const { return: returnData, statuses } = usePage().props;
    const [adminComment, setAdminComment] = React.useState(returnData.admin_comment || "");
    const [isEditingComment, setIsEditingComment] = React.useState(false);

    const handleStatusChange = (newStatus) => {
        router.patch(route("admin.returns.status", returnData.id), {
            status: newStatus,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({
                    description: "Статус возврата обновлён",
                    type: "success",
                });
            },
        });
    };

    const handleSaveAdminComment = () => {
        router.patch(route("admin.returns.admin-comment", returnData.id), {
            admin_comment: adminComment,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({
                    description: "Комментарий администратора обновлён",
                    type: "success",
                });
                setIsEditingComment(false);
            },
        });
    };

    return (
        <>
            <Head title={`Возврат #${returnData.id}`} />

            <PageHeader
                title={`Возврат #${returnData.id}`}
                actions={
                    <HStack>
                        <Button
                            onClick={() => router.visit(route("admin.returns.edit", returnData.id))}
                            colorPalette="blue"
                        >
                            <LuPencil /> Редактировать
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={() => router.visit(route("admin.returns.index"))}
                        >
                            <LuArrowLeft /> Назад к списку
                        </Button>
                    </HStack>
                }
            />

            <SimpleGrid columns={{ base: 1, lg: 2 }} gap={6} mb={6}>
                {/* Основная информация */}
                <Card.Root>
                    <Card.Header>
                        <Heading size="md">Информация о возврате</Heading>
                    </Card.Header>
                    <Card.Body>
                        <VStack align="stretch" gap={4}>
                            <HStack justify="space-between">
                                <Text color="fg.muted">UUID:</Text>
                                <Text fontFamily="mono" fontSize="sm">{returnData.uuid}</Text>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Статус:</Text>
                                <Badge colorPalette={getStatusColor(returnData.status)}>
                                    {returnData.status_label}
                                </Badge>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Сумма:</Text>
                                <Text fontWeight="bold">{returnData.total_amount} ₽</Text>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Дата создания:</Text>
                                <Text>{returnData.created_at}</Text>
                            </HStack>
                            {returnData.comment && (
                                <Box>
                                    <Text color="fg.muted" mb={1}>Комментарий клиента:</Text>
                                    <Text>{returnData.comment}</Text>
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
                            {returnData.user ? (
                                <>
                                    <HStack justify="space-between">
                                        <Text color="fg.muted">Имя:</Text>
                                        <Text>{returnData.user.name}</Text>
                                    </HStack>
                                    <HStack justify="space-between">
                                        <Text color="fg.muted">Email:</Text>
                                        <Text>{returnData.user.email}</Text>
                                    </HStack>
                                    {returnData.user.phone && (
                                        <HStack justify="space-between">
                                            <Text color="fg.muted">Телефон:</Text>
                                            <Text>{returnData.user.phone}</Text>
                                        </HStack>
                                    )}
                                </>
                            ) : (
                                <Text color="fg.muted">Данные клиента недоступны</Text>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>
            </SimpleGrid>

            {/* Управление возвратом */}
            <Card.Root mb={6}>
                <Card.Header>
                    <Heading size="md">Управление возвратом</Heading>
                </Card.Header>
                <Card.Body>
                    <VStack align="stretch" gap={4}>
                        <Box>
                            <HStack justify="space-between" mb={2}>
                                <Text fontWeight="medium">Комментарий администратора:</Text>
                                {!isEditingComment ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setIsEditingComment(true)}
                                    >
                                        <LuPencil /> Редактировать
                                    </Button>
                                ) : (
                                    <HStack>
                                        <Button
                                            size="sm"
                                            colorPalette="blue"
                                            onClick={handleSaveAdminComment}
                                        >
                                            <LuSave /> Сохранить
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setAdminComment(returnData.admin_comment || "");
                                                setIsEditingComment(false);
                                            }}
                                        >
                                            Отмена
                                        </Button>
                                    </HStack>
                                )}
                            </HStack>
                            {isEditingComment ? (
                                <Textarea
                                    value={adminComment}
                                    onChange={(e) => setAdminComment(e.target.value)}
                                    placeholder="Введите комментарий для клиента..."
                                    rows={3}
                                />
                            ) : (
                                <Text p={3} bg="gray.50" borderRadius="md" minH="80px">
                                    {returnData.admin_comment || "Комментарий не указан"}
                                </Text>
                            )}
                        </Box>
                        <Box>
                            <Text mb={2} fontWeight="medium">Изменить статус:</Text>
                            <HStack gap={2} wrap="wrap">
                                {statuses.map((status) => (
                                    <Button
                                        key={status.value}
                                        size="sm"
                                        colorPalette={getStatusColor(status.value)}
                                        variant={returnData.status === status.value ? "solid" : "outline"}
                                        onClick={() => handleStatusChange(status.value)}
                                        disabled={returnData.status === status.value}
                                    >
                                        {status.label}
                                    </Button>
                                ))}
                            </HStack>
                        </Box>
                    </VStack>
                </Card.Body>
            </Card.Root>

            {/* Позиции возврата */}
            <Card.Root>
                <Card.Header>
                    <Heading size="md">Позиции возврата ({returnData.items.length})</Heading>
                </Card.Header>
                <Card.Body p={0}>
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                <Table.ColumnHeader>Заказ</Table.ColumnHeader>
                                <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Цена</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Кол-во</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {returnData.items.map((item) => (
                                <Table.Row key={item.id}>
                                    <Table.Cell>
                                        <Text>{item.product?.name || "Товар удалён"}</Text>
                                        {item.product && (
                                            <Text color="fg.muted" fontSize="xs">
                                                ID: {item.product.id}
                                            </Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        {item.order ? (
                                            <Text fontSize="sm" fontFamily="mono">
                                                #{item.order.id}
                                            </Text>
                                        ) : "—"}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge>{item.reason_label || getReasonLabel(item.reason)}</Badge>
                                        {item.reason_comment && (
                                            <Text color="fg.muted" fontSize="xs" mt={1}>
                                                {item.reason_comment}
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
        </>
    );
};

ReturnShow.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default ReturnShow;
