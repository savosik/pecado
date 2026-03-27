import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Box, Text, Input, Badge, HStack } from '@chakra-ui/react';

export default function Edit({ individualPrice, labels }) {
    const { data, setData, put, processing, errors } = useForm({
        partner_id: individualPrice.partner_id,
        product_id: individualPrice.product_id,
        warehouse_id: individualPrice.warehouse_id,
        price: individualPrice.price,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.individual-prices.update'));
    };

    return (
        <>
            <PageHeader
                title="Редактирование цены"
                description="Изменение индивидуальной цены"
            />

            <Box as="form" onSubmit={handleSubmit} maxW="600px">
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

                <HStack mb={4}>
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
                    backRoute="admin.individual-prices.index"
                    processing={processing}
                    submitLabel="Сохранить"
                />
            </Box>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
