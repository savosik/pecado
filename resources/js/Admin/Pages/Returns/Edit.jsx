import React, { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import {
    Box,
    Button,
    Card,
    HStack,
    VStack,
    Text,
    Textarea,
    Tabs,
    createListCollection,
} from "@chakra-ui/react";
import { LuSave, LuX } from "react-icons/lu";
import AdminLayout from "@/Admin/Layouts/AdminLayout";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { Field } from "@/components/ui/field";
import { toaster } from "@/components/ui/toaster";
import { Select } from "@/components/ui/select";
import { EntitySelector } from "@/Admin/Components/EntitySelector";
import ReturnItemsEditor from "@/Admin/Components/ReturnItemsEditor";

const ReturnsEdit = ({ return: returnData, users, statuses, reasons }) => {
    // Инициализация с выбранным пользователем
    const initialUser = users?.find(u => u.id === returnData.user_id);
    const [selectedUser, setSelectedUser] = useState(initialUser ? {
        id: initialUser.id,
        name: initialUser.name,
        email: initialUser.email,
        label: `${initialUser.name} (${initialUser.email})`,
    } : null);

    const { data, setData, put, processing, errors } = useForm({
        user_id: returnData.user_id || "",
        status: returnData.status || "pending",
        comment: returnData.comment || "",
        admin_comment: returnData.admin_comment || "",
        items: returnData.items || [],
    });

    const statusesCollection = createListCollection({
        items: statuses?.map((status) => ({
            label: status.label,
            value: status.value,
        })) || [],
    });

    const handleUserChange = (user) => {
        setSelectedUser(user);
        setData("user_id", user ? user.id : "");
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        if (!data.user_id) {
            toaster.create({
                description: "Выберите пользователя",
                type: "error",
            });
            return;
        }

        if (data.items.length === 0) {
            toaster.create({
                description: "Добавьте хотя бы одну позицию возврата",
                type: "error",
            });
            return;
        }

        // Проверка причин
        const itemsWithoutReason = data.items.filter((item) => !item.reason);
        if (itemsWithoutReason.length > 0) {
            toaster.create({
                description: "Укажите причину для всех позиций возврата",
                type: "error",
            });
            return;
        }

        put(route("admin.returns.update", returnData.id), {
            onSuccess: () => {
                toaster.create({
                    description: "Возврат успешно обновлён",
                    type: "success",
                });
            },
            onError: (errors) => {
                const errorMessage = errors.error
                    || Object.values(errors).find(e => typeof e === 'string')
                    || "Ошибка при обновлении возврата";
                toaster.create({
                    description: errorMessage,
                    type: "error",
                });
            },
        });
    };

    const handleCancel = () => {
        router.visit(route("admin.returns.show", returnData.id));
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { label: "Главная", href: route("admin.dashboard") },
                { label: "Возвраты", href: route("admin.returns.index") },
                { label: `Возврат #${returnData.id}`, href: route("admin.returns.show", returnData.id) },
                { label: "Редактирование" },
            ]}
        >
            <Head title={`Редактирование возврата #${returnData.id}`} />

            <PageHeader
                title={
                    <VStack align="start" gap={1}>
                        <Text>Редактирование возврата #{returnData.id}</Text>
                        <Text fontSize="sm" color="fg.muted" fontWeight="normal">
                            UUID: {returnData.uuid}
                        </Text>
                    </VStack>
                }
                actions={
                    <HStack>
                        <Button
                            onClick={handleCancel}
                            variant="outline"
                            disabled={processing}
                        >
                            <LuX /> Отмена
                        </Button>
                        <Button
                            onClick={handleSubmit}
                            loading={processing}
                            colorPalette="blue"
                        >
                            <LuSave /> Сохранить изменения
                        </Button>
                    </HStack>
                }
            />

            <form onSubmit={handleSubmit}>
                <Tabs.Root defaultValue="general" variant="enclosed">
                    <Tabs.List>
                        <Tabs.Trigger value="general">Основное</Tabs.Trigger>
                        <Tabs.Trigger value="items">
                            Позиции возврата
                            {data.items.length > 0 && ` (${data.items.length})`}
                        </Tabs.Trigger>
                        <Tabs.Trigger value="additional">Дополнительно</Tabs.Trigger>
                    </Tabs.List>

                    <Tabs.Content value="general">
                        <Card.Root>
                            <Card.Body gap={6}>
                                {/* Пользователь */}
                                <Field
                                    label="Пользователь"
                                    required
                                    invalid={!!errors.user_id}
                                    errorText={errors.user_id}
                                >
                                    <EntitySelector
                                        value={selectedUser}
                                        onChange={handleUserChange}
                                        searchUrl="admin.orders.search-users"
                                        placeholder="Введите имя или email пользователя..."
                                        error={errors.user_id}
                                    />
                                </Field>

                                {/* Статус */}
                                <Field
                                    label="Статус"
                                    required
                                    invalid={!!errors.status}
                                    errorText={errors.status}
                                >
                                    <Select.Root
                                        collection={statusesCollection}
                                        value={data.status ? [data.status] : []}
                                        onValueChange={(e) => setData("status", e.value[0])}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Выберите статус" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            {statusesCollection.items.map((status) => (
                                                <Select.Item key={status.value} item={status}>
                                                    {status.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                {/* Комментарий пользователя */}
                                <Field
                                    label="Комментарий пользователя"
                                    invalid={!!errors.comment}
                                    errorText={errors.comment}
                                >
                                    <Textarea
                                        value={data.comment || ""}
                                        onChange={(e) => setData("comment", e.target.value)}
                                        placeholder="Опишите причину возврата..."
                                        rows={4}
                                    />
                                </Field>
                            </Card.Body>
                        </Card.Root>
                    </Tabs.Content>

                    <Tabs.Content value="items">
                        <ReturnItemsEditor
                            items={data.items}
                            onChange={(items) => setData("items", items)}
                            reasons={reasons}
                            userId={data.user_id}
                        />
                        {errors.items && (
                            <Box mt={4} p={4} bg="red.50" borderRadius="md">
                                <Field.ErrorText>{errors.items}</Field.ErrorText>
                            </Box>
                        )}
                    </Tabs.Content>

                    <Tabs.Content value="additional">
                        <Card.Root>
                            <Card.Body gap={6}>
                                <Field
                                    label="Комментарий администратора"
                                    invalid={!!errors.admin_comment}
                                    errorText={errors.admin_comment}
                                >
                                    <Textarea
                                        value={data.admin_comment || ""}
                                        onChange={(e) => setData("admin_comment", e.target.value)}
                                        placeholder="Комментарий для внутреннего использования..."
                                        rows={4}
                                    />
                                </Field>
                            </Card.Body>
                        </Card.Root>
                    </Tabs.Content>
                </Tabs.Root>
            </form>
        </AdminLayout>
    );
};

export default ReturnsEdit;
