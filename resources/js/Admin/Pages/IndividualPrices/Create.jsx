import React, { useRef } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, EntitySelector, FormField, FormActions } from '@/Admin/Components';
import { Card, Stack, Text, Input } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        partner_id: '',
        product_id: '',
        warehouse_id: '',
        price: '',
    });

    const closeAfterSaveRef = useRef(false);

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.individual-prices.store'), {
            onSuccess: () => {
                toaster.create({
                    description: 'Индивидуальная цена сохранена',
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
            <Head title="Создание индивидуальной цены" />

            <PageHeader
                title="Новая индивидуальная цена"
                description="Создание индивидуальной цены для партнёра"
                backUrl={route('admin.individual-prices.index')}
            />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={4} maxW="xl">
                            <FormField label="Партнёр" error={errors.partner_id} required>
                                <EntitySelector
                                    value={data.partner_id}
                                    onChange={(item) => setData('partner_id', item?.id || '')}
                                    searchUrl={route('admin.individual-prices.search-partners')}
                                    placeholder="Поиск по имени или email..."
                                    displayField="label"
                                    error={errors.partner_id}
                                    renderItem={(item) => (
                                        <>
                                            <Text fontWeight="medium">{item.label}</Text>
                                            {item.email && <Text fontSize="xs" color="fg.muted">{item.email}</Text>}
                                        </>
                                    )}
                                />
                            </FormField>

                            <FormField label="Товар" error={errors.product_id} required>
                                <EntitySelector
                                    value={data.product_id}
                                    onChange={(item) => setData('product_id', item?.id || '')}
                                    searchUrl={route('admin.individual-prices.search-products')}
                                    placeholder="Поиск по названию или артикулу..."
                                    displayField="label"
                                    error={errors.product_id}
                                    renderItem={(item) => (
                                        <>
                                            <Text fontWeight="medium">{item.label}</Text>
                                            {item.sku && <Text fontSize="xs" color="fg.muted">SKU: {item.sku}</Text>}
                                        </>
                                    )}
                                />
                            </FormField>

                            <FormField label="Склад" error={errors.warehouse_id} required>
                                <EntitySelector
                                    value={data.warehouse_id}
                                    onChange={(item) => setData('warehouse_id', item?.id || '')}
                                    searchUrl={route('admin.individual-prices.search-warehouses')}
                                    placeholder="Поиск по названию склада..."
                                    displayField="label"
                                    error={errors.warehouse_id}
                                />
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

                            <FormActions
                                onSaveAndClose={handleSaveAndClose}
                                backUrl={route('admin.individual-prices.index')}
                                isLoading={processing}
                                submitLabel="Создать"
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
