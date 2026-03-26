import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ tag }) {
    const { data, setData, put, processing, errors , transform } = useForm({
        name: tag.display_name || '',
        type: tag.type || '',
        order_column: tag.order_column || 0,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.tags.update', tag.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Тег успешно обновлён',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при обновлении тега',
                    description: 'Проверьте правильность заполнения полей',
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
            <PageHeader title={`Редактировать тег: ${tag.display_name}`} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <FormField label="Название" error={errors.name} required>
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
                                onSaveAndClose={handleSaveAndClose}
                            submitLabel="Сохранить изменения"
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

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
