import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, SelectRelation } from '@/Admin/Components';
import { Box, Card, Input, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create({ products }) {
    const { data, setData, post, processing, errors } = useForm({
        product_id: '',
        barcode: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.product-barcodes.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Штрихкод добавлен',
                    description: 'Штрихкод успешно привязан к товару',
                    type: 'success',
                });
            },
        });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { label: 'Главная', href: route('admin.dashboard') },
                { label: 'Штрихкоды', href: route('admin.product-barcodes.index') },
                { label: 'Создать' },
            ]}
        >
            <Box p={6}>
                <PageHeader title="Добавить штрихкод" description="Привязка нового штрихкода к товару" />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <SelectRelation
                                    label="Товар *"
                                    value={data.product_id}
                                    onChange={(val) => setData('product_id', val)}
                                    options={products.map(p => ({ value: p.id, label: p.name }))}
                                    placeholder="Выберите товар"
                                    error={errors.product_id}
                                />

                                <FormField label="Штрихкод *" error={errors.barcode}>
                                    <Input
                                        value={data.barcode}
                                        onChange={(e) => setData('barcode', e.target.value)}
                                        placeholder="Например: 4601234567890"
                                    />
                                </FormField>
                            </Stack>
                        </Card.Body>

                        <Card.Footer>
                            <FormActions
                                loading={processing}
                                onCancel={() => window.history.back()}
                                submitLabel="Добавить штрихкод"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
            </Box>
        </AdminLayout>
    );
}
