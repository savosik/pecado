import React, { useState , useRef } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import {
    Box,
    Button,
    Card,
    HStack,
    VStack,
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

const ReturnsCreate = ({ statuses, reasons }) => {
    const { data, setData, post, processing, errors , transform } = useForm({
        user_id: "",
        status: "pending",
        comment: "",
        admin_comment: "",
        items: [],
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const [selectedUser, setSelectedUser] = useState(null);

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

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;

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

        post(route("admin.returns.store"), {
            onSuccess: () => {
                toaster.create({
                    description: "Возврат успешно создан",
                    type: "success",
                });
            },
            onError: (errors) => {
                const errorMessage = errors.error
                    || Object.values(errors).find(e => typeof e === 'string')
                    || "Ошибка при создании возврата";
                toaster.create({
                    description: errorMessage,
                    type: "error",
                });
            },
        });
    };

    const handleCancel = () => {
        router.visit(route("admin.returns.index"));
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <Head title="Создание возврата" />

            <PageHeader
                title="Создание возврата"
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
                            <LuSave /> Сохранить
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
        </>
    );
};

ReturnsCreate.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default ReturnsCreate;
