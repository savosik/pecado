import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, SelectRelation, ProductSelector } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',

        code: '',
        external_id: '',
        products: [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        transform((data) => ({
            ...data,
            products: data.products.map(p => p.id),
        }));

        post(route('admin.product-models.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Модель создана',
                    description: 'Модель успешно добавлена в систему',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось создать модель',
                    type: 'error',
                });
            },
        });
    };

    return (
        <AdminLayout>
            <Box p={6}>
                <PageHeader
                    title="Создать модель"
                    description="Добавление новой модели товара"
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
                                submitLabel="Создать модель"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
            </Box>
        </AdminLayout>
    );
}
