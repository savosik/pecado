import React, { useRef } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Stack, Text, Input, Badge, HStack, Box } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ individualPrice, labels }) {
    const { data, setData, put, processing, errors } = useForm({
        partner_id: individualPrice.partner_id,
        product_id: individualPrice.product_id,
        warehouse_id: individualPrice.warehouse_id,
        price: individualPrice.price,
    });

    const closeAfterSaveRef = useRef(false);

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.individual-prices.update'), {
            onSuccess: () => {
                toaster.create({
                    description: 'Цена обновлена',
                    type: 'success',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <Head title="Редактирование цены" />

            <PageHeader
                title="Редактирование цены"
                description="Изменение индивидуальной цены"
                backUrl={route('admin.individual-prices.index')}
            />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={4} maxW="xl">
                            <FormField label="Партнёр">
                                <Box px={3} py={2} borderWidth="1px" borderRadius="md" bg="bg.subtle">
                                    <Text fontWeight="medium">{labels.partner}</Text>
                                </Box>
                            </FormField>

                            <FormField label="Товар">
                                <Box px={3} py={2} borderWidth="1px" borderRadius="md" bg="bg.subtle">
                                    <Text fontWeight="medium">{labels.product}</Text>
                                </Box>
                            </FormField>

                            <FormField label="Склад">
                                <Box px={3} py={2} borderWidth="1px" borderRadius="md" bg="bg.subtle">
                                    <Text fontWeight="medium">{labels.warehouse}</Text>
                                </Box>
                            </FormField>

                            <FormField label="Цена, ₽" error={errors.price} required>
                                <Input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={data.price}
                                    onChange={(e) => setData('price', e.target.value)}
                                    placeholder="0.00"
                                />
                            </FormField>

                            <HStack>
                                <Text fontSize="sm" color="fg.muted">
                                    Последнее обновление:
                                </Text>
                                <Badge colorPalette="gray" variant="subtle">
                                    {individualPrice.updated_at
                                        ? new Date(individualPrice.updated_at).toLocaleString('ru-RU')
                                        : '—'}
                                </Badge>
                            </HStack>

                            <FormActions
                                onSaveAndClose={handleSaveAndClose}
                                backUrl={route('admin.individual-prices.index')}
                                isLoading={processing}
                                submitLabel="Сохранить"
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
