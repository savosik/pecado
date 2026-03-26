import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ProductSelector } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        uuid: '',
        products: [],
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

        post(route('admin.product-segments.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Сегмент создан',
                    description: 'Сегмент номенклатуры успешно добавлен в систему',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось создать сегмент. Проверьте правильность заполнения полей.',
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
                title="Создать сегмент номенклатуры"
                description="Добавление нового сегмента товаров (синхронизируется из 1С)"
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
                            submitLabel="Создать сегмент"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
