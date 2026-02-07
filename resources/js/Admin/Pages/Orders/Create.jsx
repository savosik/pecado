import React, { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import {
    Box,
    Button,
    Card,
    Grid,
    HStack,
    Input,
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
import OrderItemsEditor from "@/Admin/Components/OrderItemsEditor";
import { EntitySelector } from "@/Admin/Components/EntitySelector";

const Create = ({ statuses, currencies }) => {
    const { data, setData, post, processing, errors } = useForm({
        user_id: "",
        company_id: "",
        status: "pending",
        comment: "",
        delivery_address: "",
        currency_code: "RUB",
        items: [],
    });

    // State for selected entities (for display)
    const [selectedUser, setSelectedUser] = useState(null);
    const [selectedCompany, setSelectedCompany] = useState(null);

    const statusesCollection = createListCollection({
        items: statuses.map((status) => ({
            label: status.label,
            value: status.value,
        })),
    });

    const currenciesCollection = createListCollection({
        items: currencies ? currencies.map((currency) => ({
            label: `${currency.name} (${currency.code})`,
            value: currency.code,
        })) : [],
    });

    // Handler for user selection
    const handleUserChange = (user) => {
        setSelectedUser(user);
        setData("user_id", user ? user.id : "");
        // Reset company when user changes
        if (!user || (selectedCompany && selectedCompany.user_id !== user.id)) {
            setSelectedCompany(null);
            setData("company_id", "");
        }
    };

    // Handler for company selection
    const handleCompanyChange = (company) => {
        setSelectedCompany(company);
        setData("company_id", company ? company.id : "");
        // Auto-select user if company is selected and user is empty
        if (company && company.user_id && !selectedUser) {
            setSelectedUser({
                id: company.user_id,
                name: company.user_name,
                email: company.user_email,
                label: `${company.user_name} (${company.user_email})`,
            });
            setData("user_id", company.user_id);
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        if (!data.items || data.items.length === 0) {
            toaster.create({
                description: "Добавьте хотя бы одну позицию в заказ",
                type: "error",
            });
            return;
        }

        post(route("admin.orders.store"), {
            onSuccess: () => {
                toaster.create({
                    description: "Заказ успешно создан",
                    type: "success",
                });
            },
            onError: (errors) => {
                console.log('Form errors:', errors);
                // Show the first error message, prioritizing 'error' key
                const errorMessage = errors.error
                    || Object.values(errors).find(e => typeof e === 'string')
                    || "Ошибка при создании заказа";
                toaster.create({
                    description: errorMessage,
                    type: "error",
                    duration: 10000, // Show for 10 seconds
                });
            },
        });
    };

    const handleCancel = () => {
        router.visit(route("admin.orders.index"));
    };

    return (
        <>
            <Head title="Создать заказ" />

            <PageHeader
                title="Создать заказ"
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
                        <Tabs.Trigger value="items">Позиции заказа</Tabs.Trigger>
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

                                {/* Компания */}
                                <Field
                                    label="Компания"
                                    required
                                    invalid={!!errors.company_id}
                                    errorText={errors.company_id}
                                >
                                    <EntitySelector
                                        value={selectedCompany}
                                        onChange={handleCompanyChange}
                                        searchUrl="admin.orders.search-companies"
                                        searchParams={selectedUser ? { user_id: selectedUser.id } : {}}
                                        placeholder={selectedUser ? "Введите название компании..." : "Сначала выберите пользователя или введите название..."}
                                        error={errors.company_id}
                                    />
                                </Field>

                                {/* Статус и Валюта в две колонки */}
                                <Grid templateColumns={{ base: "1fr", md: "1fr 1fr" }} gap={6}>
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

                                    {/* Валюта */}
                                    <Field
                                        label="Валюта"
                                        invalid={!!errors.currency_code}
                                        errorText={errors.currency_code}
                                    >
                                        <Select.Root
                                            collection={currenciesCollection}
                                            value={data.currency_code ? [data.currency_code] : []}
                                            onValueChange={(e) => setData("currency_code", e.value[0])}
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Выберите валюту" />
                                            </Select.Trigger>
                                            <Select.Content>
                                                {currenciesCollection.items.map((currency) => (
                                                    <Select.Item key={currency.value} item={currency}>
                                                        {currency.label}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>
                                </Grid>
                            </Card.Body>
                        </Card.Root>
                    </Tabs.Content>

                    <Tabs.Content value="items">
                        <OrderItemsEditor
                            value={data.items}
                            onChange={(items) => setData("items", items)}
                            errors={errors}
                            userId={data.user_id}
                            currencyCode={data.currency_code}
                        />
                    </Tabs.Content>

                    <Tabs.Content value="additional">
                        <Card.Root>
                            <Card.Body gap={4}>
                                <Field
                                    label="Адрес доставки"
                                    invalid={!!errors.delivery_address}
                                    errorText={errors.delivery_address}
                                >
                                    <Textarea
                                        value={data.delivery_address || ""}
                                        onChange={(e) => setData("delivery_address", e.target.value)}
                                        placeholder="Введите адрес доставки..."
                                        rows={3}
                                    />
                                </Field>
                                <Field
                                    label="Комментарий"
                                    invalid={!!errors.comment}
                                    errorText={errors.comment}
                                >
                                    <Textarea
                                        value={data.comment || ""}
                                        onChange={(e) => setData("comment", e.target.value)}
                                        placeholder="Введите комментарий к заказу..."
                                        rows={5}
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

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default Create;
