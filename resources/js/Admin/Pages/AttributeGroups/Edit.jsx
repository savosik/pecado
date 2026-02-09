import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ attributeGroup }) {
    const { data, setData, put, processing, errors, transform } = useForm({
        name: attributeGroup.name || '',
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.attribute-groups.update', attributeGroup.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Группа атрибутов обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при обновлении группы',
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
            <PageHeader title={`Редактировать группу: ${attributeGroup.name}`} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <FormField label="Название" error={errors.name} required>
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                />
                            </FormField>

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
