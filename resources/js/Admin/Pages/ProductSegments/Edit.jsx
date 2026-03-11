import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ProductSelector } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid, Text } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ segment }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        _method: 'PUT',
        name: segment.name || '',
        uuid: segment.uuid || '',
        products: segment.products || [],
    });

    const closeAfterSaveRef = useRef(false);

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;

        transform((data) => ({
            ...data,
            _close: closeAfterSaveRef.current ? 1 : 0,
            product_ids: data.products.map(p => p.id),
        }));

        post(route('admin.product-segments.update', segment.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Сегмент обновлён',
                    description: 'Информация о сегменте номенклатуры успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось обновить сегмент. Проверьте правильность заполнения полей.',
                    type: 'error',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader
                title={`Редактирование: ${segment.name}`}
                description="Изменение информации о сегменте номенклатуры"
            />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Название" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Например: Лубриканты"
                                    />
                                </FormField>

                                <FormField label="UUID (из 1С)" error={errors.uuid}>
                                    <Input
                                        value={data.uuid}
                                        onChange={(e) => setData('uuid', e.target.value)}
                                        placeholder="seg-prod-001"
                                        fontFamily="mono"
                                    />
                                </FormField>
                            </SimpleGrid>

                            {segment.updated_at && (
                                <Text fontSize="xs" color="fg.muted">
                                    Последнее обновление: {segment.updated_at}
                                </Text>
                            )}

                            <Box>
                                <FormField label="Товары в сегменте" error={errors.products}>
                                    <ProductSelector
                                        value={data.products}
                                        onChange={(products) => setData('products', products)}
                                        error={errors.products}
                                    />
                                </FormField>
                            </Box>
                        </Stack>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Сохранить изменения"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
