import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        user_id: '',
        name: '',
        address: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.delivery-addresses.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Адрес создан',
                    description: 'Адрес доставки успешно добавлен',
                    type: 'success',
                });
            },
        });
    };

    return (
        <>
                <PageHeader title="Создать адрес доставки" description="Добавление нового адреса доставки" />

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
                                    />
                                </FormField>

                                <FormField label="Название *" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Домашний адрес, Офис и т.д."
                                    />
                                </FormField>

                                <FormField label="Адрес *" error={errors.address} required>
                                    <Textarea
                                        value={data.address}
                                        onChange={(e) => setData('address', e.target.value)}
                                        placeholder="Полный адрес доставки"
                                        rows={4}
                                    />
                                </FormField>
                            </Stack>
                        </Card.Body>

                        <Card.Footer>
                            <FormActions
                                loading={processing}
                                onCancel={() => window.history.back()}
                                submitLabel="Создать адрес"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
