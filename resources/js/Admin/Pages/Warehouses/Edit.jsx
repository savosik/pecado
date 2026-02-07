import React from "react";
import { Box, Button, Input, Stack, Card } from "@chakra-ui/react";
import { Head, useForm, Link } from "@inertiajs/react";
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from "@/Admin/Components/PageHeader";
import { FormField } from "@/Admin/Components/FormField";
import { FormActions } from "@/Admin/Components/FormActions";
import { toaster } from "@/components/ui/toaster";

const WarehousesEdit = ({ warehouse }) => {
    const { data, setData, put, processing, errors } = useForm({
        name: warehouse.name || "",
        external_id: warehouse.external_id || "",
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route("admin.warehouses.update", warehouse.id), {
            onSuccess: () => {
                toaster.create({
                    description: "Склад успешно обновлен",
                    type: "success",
                });
            },
        });
    };

    return (
        <>
            <Head title={`Редактирование склада: ${warehouse.name}`} />

            <PageHeader
                title={`Редактирование: ${warehouse.name}`}
                backUrl={route("admin.warehouses.index")}
            />

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
                                submitLabel="Сохранить"
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
};

WarehousesEdit.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default WarehousesEdit;
