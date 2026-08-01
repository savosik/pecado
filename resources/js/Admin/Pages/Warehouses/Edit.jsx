import React, { useRef } from "react";
import { Box, Button, Input, Stack, Card } from "@chakra-ui/react";
import { Head, useForm, Link } from "@inertiajs/react";
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from "@/Admin/Components/PageHeader";
import { FormField } from "@/Admin/Components/FormField";
import { FormActions } from "@/Admin/Components/FormActions";
import { Checkbox } from "@/components/ui/checkbox";
import { Text } from "@chakra-ui/react";
import { toaster } from "@/components/ui/toaster";

const WarehousesEdit = ({ warehouse }) => {
    const { data, setData, put, processing, errors , transform } = useForm({
        name: warehouse.name || "",
        external_id: warehouse.external_id || "",
        is_defect: !!warehouse.is_defect,
        is_promo_sample: !!warehouse.is_promo_sample,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route("admin.warehouses.update", warehouse.id), {
            onSuccess: () => {
                toaster.create({
                    description: "Склад успешно обновлен",
                    type: "success",
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
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

                            <FormField label="Склад некондиции" error={errors.is_defect}>
                                <Checkbox
                                    checked={data.is_defect}
                                    disabled={data.is_promo_sample}
                                    onCheckedChange={(e) => setData("is_defect", !!e.checked)}
                                >
                                    С этого склада отгружаются заказы уценки
                                </Checkbox>
                                <Text fontSize="xs" color="fg.muted" mt={1}>
                                    Остатки такого склада не показываются на витрине; уценённые товары ведёт кабинет склада.
                                </Text>
                            </FormField>

                            <FormField label="Склад рекламных образцов" error={errors.is_promo_sample}>
                                <Checkbox
                                    checked={data.is_promo_sample}
                                    disabled={data.is_defect}
                                    onCheckedChange={(e) => setData("is_promo_sample", !!e.checked)}
                                >
                                    С этого склада отгружаются пробники по акциям
                                </Checkbox>
                                <Text fontSize="xs" color="fg.muted" mt={1}>
                                    Остатки такого склада не показываются на витрине: пробники выдаёт только движок акций, в накладную клиенту они не попадают.
                                </Text>
                            </FormField>

                            <FormActions
                                onSaveAndClose={handleSaveAndClose}
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
