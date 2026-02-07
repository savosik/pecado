import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        type: '',
        order_column: 0,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.tags.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Тег успешно создан',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при создании тега',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    return (
        <>
            <PageHeader title="Создать тег" />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <FormField label="Название *" error={errors.name} required>
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Введите название тега"
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Тип" error={errors.type}>
                                    <Input
                                        value={data.type}
                                        onChange={(e) => setData('type', e.target.value)}
                                        placeholder="Например: category, status"
                                    />
                                </FormField>

                                <FormField label="Порядок сортировки" error={errors.order_column}>
                                    <Input
                                        type="number"
                                        value={data.order_column}
                                        onChange={(e) => setData('order_column', parseInt(e.target.value) || 0)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormActions
                                submitLabel="Создать тег"
                                onCancel={() => window.history.back()}
                                processing={processing}
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
