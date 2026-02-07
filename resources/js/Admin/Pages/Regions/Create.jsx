import React from "react";
import { Box, Button, Input, Stack, Card } from "@chakra-ui/react";
import { Head, useForm, Link } from "@inertiajs/react";
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from "@/Admin/Components/PageHeader";
import { FormField } from "@/Admin/Components/FormField";
import { FormActions } from "@/Admin/Components/FormActions";
import { SelectRelation } from "@/Admin/Components/SelectRelation";
import { toaster } from "@/components/ui/toaster";

const RegionsCreate = ({ warehouses }) => {
    const { data, setData, post, processing, errors } = useForm({
        name: "",
        primary_warehouse_ids: [],
        preorder_warehouse_ids: [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route("admin.regions.store"), {
            onSuccess: () => {
                toaster.create({
                    description: "Регион успешно создан",
                    type: "success",
                });
            },
        });
    };

    return (
        <>
            <Head title="Создание региона" />

            <PageHeader title="Создание региона" backUrl={route("admin.regions.index")} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={4} maxW="xl">
                            <FormField label="Название" error={errors.name} required>
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData("name", e.target.value)}
                                    placeholder="Введите название региона"
                                />
                            </FormField>

                            <SelectRelation
                                label="Основные склады"
                                options={warehouses}
                                value={data.primary_warehouse_ids}
                                onChange={(ids) => setData("primary_warehouse_ids", ids)}
                                multiple
                                error={errors.primary_warehouse_ids}
                                placeholder="Выберите основные склады"
                            />

                            <SelectRelation
                                label="Склады предзаказа"
                                options={warehouses}
                                value={data.preorder_warehouse_ids}
                                onChange={(ids) => setData("preorder_warehouse_ids", ids)}
                                multiple
                                error={errors.preorder_warehouse_ids}
                                placeholder="Выберите склады для предзаказа"
                            />

                            <FormActions
                                backUrl={route("admin.regions.index")}
                                isLoading={processing}
                                submitLabel="Создать"
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
};

RegionsCreate.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default RegionsCreate;
