import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Box, Card, Stack, Input, SimpleGrid } from '@chakra-ui/react';

export default function Edit({ agreement }) {
    const { data, setData, put, processing, errors } = useForm({
        name: agreement.name || '',
        is_active: agreement.is_active || false,
        starts_at: agreement.starts_at || '',
        ends_at: agreement.ends_at || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.agreements.update', agreement.id));
    };

    return (
        <form onSubmit={handleSubmit}>
            <PageHeader
                title={`Редактирование: ${agreement.name}`}
                description="Изменение настроек индивидуального соглашения"
                backUrl={route('admin.agreements.index')}
            />

            <Box maxW="3xl" mb={8}>
                <Card.Root mb={6}>
                    <Card.Body>
                        <Stack gap={6}>
                            <FormField label="Название соглашения" error={errors.name} required>
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Название соглашения"
                                />
                            </FormField>

                            <FormField label="Статус" error={errors.is_active}>
                                <label style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                    <input
                                        type="checkbox"
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                    />
                                    Активно
                                </label>
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Дата начала" error={errors.starts_at}>
                                    <Input
                                        type="datetime-local"
                                        value={data.starts_at}
                                        onChange={(e) => setData('starts_at', e.target.value)}
                                    />
                                </FormField>
                                <FormField label="Дата окончания" error={errors.ends_at}>
                                    <Input
                                        type="datetime-local"
                                        value={data.ends_at}
                                        onChange={(e) => setData('ends_at', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>
                        </Stack>
                    </Card.Body>
                </Card.Root>

                <FormActions
                    onCancel={() => router.visit(route('admin.agreements.index'))}
                    isProcessing={processing}
                />
            </Box>
        </form>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
