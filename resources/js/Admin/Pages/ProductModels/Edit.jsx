import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, SelectRelation, ProductSelector } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ productModel }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        _method: 'PUT',
        name: productModel.name || '',

        code: productModel.code || '',
        external_id: productModel.external_id || '',
        products: productModel.products || [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        transform((data) => ({
            ...data,
            products: data.products.map(p => p.id),
        }));

        post(route('admin.product-models.update', productModel.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Модель обновлена',
                    description: 'Информация о модели успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось обновить модель',
                    type: 'error',
                });
            },
        });
    };

    return (
        <>
                <PageHeader
                    title={`Редактирование: ${productModel.name}`}
                    description="Изменение информации о модели"
                />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField
                                        label="Название модели"
                                        required
                                        error={errors.name}
                                    >
                                        <Input
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="Введите название модели"
                                        />
                                    </FormField>



                                    <FormField
                                        label="Код модели (Артикул)"
                                        error={errors.code}
                                        helperText="Внутренний код или артикул производителя"
                                    >
                                        <Input
                                            value={data.code}
                                            onChange={(e) => setData('code', e.target.value)}
                                            placeholder="Введите код"
                                        />
                                    </FormField>

                                    <FormField
                                        label="External ID"
                                        error={errors.external_id}
                                    >
                                        <Input
                                            value={data.external_id}
                                            onChange={(e) => setData('external_id', e.target.value)}
                                            placeholder="Внешний идентификатор"
                                        />
                                    </FormField>
                                </SimpleGrid>

                                <Box>
                                    <FormField label="Привязанные товары" error={errors.products}>
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
