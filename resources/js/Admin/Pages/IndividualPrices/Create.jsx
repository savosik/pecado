import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, EntitySelector, FormField, FormActions } from '@/Admin/Components';
import { Box, Text, Input } from '@chakra-ui/react';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        partner_id: '',
        product_id: '',
        warehouse_id: '',
        price: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.individual-prices.store'));
    };

    return (
        <>
            <PageHeader
                title="Новая индивидуальная цена"
                description="Создание индивидуальной цены для партнёра"
            />

            <Box as="form" onSubmit={handleSubmit} maxW="600px">
                <FormField label="Партнёр" error={errors.partner_id} required>
                    <EntitySelector
                        value={data.partner_id}
                        onChange={(item) => setData('partner_id', item?.id || '')}
                        searchUrl={route('admin.individual-prices.search-partners')}
                        placeholder="Поиск по имени или email..."
                        displayField="label"
                        valueKey="id"
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
                        valueKey="id"
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
                        valueKey="id"
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
                    backRoute="admin.individual-prices.index"
                    processing={processing}
                    submitLabel="Создать"
                />
            </Box>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
