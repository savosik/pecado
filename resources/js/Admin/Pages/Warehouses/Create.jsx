import React from "react";
import { Box, Button, Input, Stack, Card } from "@chakra-ui/react";
import { Head, useForm, Link } from "@inertiajs/react";
import { AdminLayout } from "@/Admin/Layouts/AdminLayout";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { FormField } from "@/Admin/Components/FormField";
import { FormActions } from "@/Admin/Components/FormActions";
import { toaster } from "@/components/ui/toaster";

const WarehousesCreate = () => {
    const { data, setData, post, processing, errors } = useForm({
        name: "",
        external_id: "",
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route("admin.warehouses.store"), {
            onSuccess: () => {
                toaster.create({
                    description: "Склад успешно создан",
                    type: "success",
                });
            },
        });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { label: "Главная", href: route("admin.dashboard") },
                { label: "Склады", href: route("admin.warehouses.index") },
                { label: "Создание" },
            ]}
        >
            <Head title="Создание склада" />

            <PageHeader title="Создание склада" backUrl={route("admin.warehouses.index")} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={4} maxW="xl">
                            <FormField label="Название" error={errors.name} required>
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData("name", e.target.value)}
                                    placeholder="Введите название склада"
                                />
                            </FormField>

                            <FormField label="Внешний ID" error={errors.external_id}>
                                <Input
                                    value={data.external_id}
                                    onChange={(e) => setData("external_id", e.target.value)}
                                    placeholder="ID во внешней системе (опционально)"
                                />
                            </FormField>

                            <FormActions
                                backUrl={route("admin.warehouses.index")}
                                isLoading={processing}
                                submitLabel="Создать"
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </AdminLayout>
    );
};

export default WarehousesCreate;
