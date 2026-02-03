import React, { useState } from "react";
import {
    Box,
    Button,
    Card,
    HStack,
    VStack,
    Input,
    Text,
    Textarea,
    Tabs,
} from "@chakra-ui/react";
import { Head, router, usePage } from "@inertiajs/react";
import { LuSave, LuX } from "react-icons/lu";
import { AdminLayout } from "@/Admin/Layouts/AdminLayout";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { Field } from "@/components/ui/field";
import { toaster } from "@/components/ui/toaster";
import { Select } from "@/components/ui/select";
import ReturnItemsEditor from "@/Admin/Components/ReturnItemsEditor";

const ReturnsEdit = () => {
    const { return: returnData, users, statuses, reasons } = usePage().props;
    const [submitting, setSubmitting] = useState(false);
    const [activeTab, setActiveTab] = useState("general");
    const [errors, setErrors] = useState({});

    const [formData, setFormData] = useState({
        user_id: returnData.user_id || "",
        status: returnData.status || "pending",
        comment: returnData.comment || "",
        admin_comment: returnData.admin_comment || "",
        items: returnData.items || [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        setErrors({});

        // Валидация
        if (!formData.user_id) {
            toaster.create({
                description: "Выберите пользователя",
                type: "error",
            });
            setActiveTab("general");
            return;
        }

        if (formData.items.length === 0) {
            toaster.create({
                description: "Добавьте хотя бы одну позицию возврата",
                type: "error",
            });
            setActiveTab("items");
            return;
        }

        // Проверка причин
        const itemsWithoutReason = formData.items.filter((item) => !item.reason);
        if (itemsWithoutReason.length > 0) {
            toaster.create({
                description: "Укажите причину для всех позиций возврата",
                type: "error",
            });
            setActiveTab("items");
            return;
        }

        setSubmitting(true);
        router.put(route("admin.returns.update", returnData.id), formData, {
            onSuccess: () => {
                toaster.create({
                    description: "Возврат успешно обновлён",
                    type: "success",
                });
            },
            onError: (errors) => {
                setErrors(errors);
                toaster.create({
                    description: "Ошибка при обновлении возврата",
                    type: "error",
                });
                setSubmitting(false);
            },
        });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { label: "Главная", href: route("admin.dashboard") },
                { label: "Возвраты", href: route("admin.returns.index") },
                { label: `Возврат #${returnData.id} `, href: route("admin.returns.show", returnData.id) },
                { label: "Редактирование" },
            ]}
        >
            <Head title={`Редактирование возврата #${returnData.id} `} />

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
                    <Button
                        variant="ghost"
                        onClick={() => router.visit(route("admin.returns.show", returnData.id))}
                    >
                        <LuX /> Отмена
                    </Button>
                }
            />

            <form onSubmit={handleSubmit}>
                <Tabs.Root value={activeTab} onValueChange={(e) => setActiveTab(e.value)}>
                    <Tabs.List>
                        <Tabs.Trigger value="general">Основное</Tabs.Trigger>
                        <Tabs.Trigger value="items">
                            Позиции возврата
                            {formData.items.length > 0 && ` (${formData.items.length})`}
                        </Tabs.Trigger>
                        <Tabs.Trigger value="additional">Дополнительно</Tabs.Trigger>
                    </Tabs.List>

                    <Box py={6}>
                        {/* Таб: Основное */}
                        <Tabs.Content value="general">
                            <VStack align="stretch" gap={6}>
                                <Field label="Пользователь" required invalid={!!errors.user_id}>
                                    <Select.Root
                                        value={formData.user_id ? [String(formData.user_id)] : []}
                                        onValueChange={(e) =>
                                            setFormData({ ...formData, user_id: e.value[0] || "" })
                                        }
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Выберите пользователя" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            {users?.map((user) => (
                                                <Select.Item key={user.id} item={String(user.id)}>
                                                    {user.name} ({user.email})
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                    {errors.user_id && (
                                        <Field.ErrorText>{errors.user_id}</Field.ErrorText>
                                    )}
                                </Field>

                                <Field label="Статус" required invalid={!!errors.status}>
                                    <Select.Root
                                        value={formData.status ? [formData.status] : []}
                                        onValueChange={(e) =>
                                            setFormData({ ...formData, status: e.value[0] })
                                        }
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Выберите статус" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            {statuses?.map((status) => (
                                                <Select.Item key={status.value} item={status.value}>
                                                    {status.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                    {errors.status && (
                                        <Field.ErrorText>{errors.status}</Field.ErrorText>
                                    )}
                                </Field>

                                <Field label="Комментарий пользователя" invalid={!!errors.comment}>
                                    <Textarea
                                        value={formData.comment}
                                        onChange={(e) =>
                                            setFormData({ ...formData, comment: e.target.value })
                                        }
                                        placeholder="Опишите причину возврата..."
                                        rows={4}
                                    />
                                    {errors.comment && (
                                        <Field.ErrorText>{errors.comment}</Field.ErrorText>
                                    )}
                                </Field>
                            </VStack>
                        </Tabs.Content>

                        {/* Таб: Позиции возврата */}
                        <Tabs.Content value="items">
                            <ReturnItemsEditor
                                items={formData.items}
                                onChange={(items) => setFormData({ ...formData, items })}
                                reasons={reasons}
                            />
                            {errors.items && (
                                <Box mt={4} p={4} bg="red.50" borderRadius="md">
                                    <Field.ErrorText>{errors.items}</Field.ErrorText>
                                </Box>
                            )}
                        </Tabs.Content>

                        {/* Таб: Дополнительно */}
                        <Tabs.Content value="additional">
                            <VStack align="stretch" gap={6}>
                                <Field label="Комментарий администратора" invalid={!!errors.admin_comment}>
                                    <Textarea
                                        value={formData.admin_comment}
                                        onChange={(e) =>
                                            setFormData({ ...formData, admin_comment: e.target.value })
                                        }
                                        placeholder="Комментарий для внутреннего использования..."
                                        rows={4}
                                    />
                                    {errors.admin_comment && (
                                        <Field.ErrorText>{errors.admin_comment}</Field.ErrorText>
                                    )}
                                </Field>
                            </VStack>
                        </Tabs.Content>
                    </Box>
                </Tabs.Root>

                {/* Кнопки */}
                <HStack mt={6} justify="flex-end">
                    <Button
                        variant="outline"
                        onClick={() => router.visit(route("admin.returns.show", returnData.id))}
                        disabled={submitting}
                    >
                        <LuX /> Отмена
                    </Button>
                    <Button
                        type="submit"
                        colorPalette="blue"
                        loading={submitting}
                    >
                        <LuSave /> Сохранить изменения
                    </Button>
                </HStack>
            </form>
        </AdminLayout>
    );
};

export default ReturnsEdit;
