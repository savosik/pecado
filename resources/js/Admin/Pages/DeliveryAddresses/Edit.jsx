import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ deliveryAddress }) {
    const { data, setData, put, processing, errors } = useForm({
        user_id: deliveryAddress.user_id || '',
        name: deliveryAddress.name || '',
        address: deliveryAddress.address || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.delivery-addresses.update', deliveryAddress.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Адрес обновлен',
                    description: 'Информация об адресе доставки успешно обновлена',
                    type: 'success',
                });
            },
        });
    };

    return (
        <>
                <PageHeader
                    title={`Редактирование: ${deliveryAddress.name}`}
                    description="Изменение информации об адресе доставки"
                />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <FormField label="Пользователь *" error={errors.user_id} required>
                                    <EntitySelector
                                        searchUrl={route('admin.users.search')}
                                        placeholder="Выберите пользователя"
                                        value={data.user_id}
                                        onChange={(value) => setData('user_id', value)}
                                        error={errors.user_id}
                                        initialDisplay={deliveryAddress.user?.name}
                                    />
                                </FormField>

                                <FormField label="Название *" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Адрес *" error={errors.address} required>
                                    <Textarea
                                        value={data.address}
                                        onChange={(e) => setData('address', e.target.value)}
                                        rows={4}
                                    />
                                </FormField>
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
