import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, SelectRelation } from '@/Admin/Components';
import { Box, Card, Input, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ productBarcode, products }) {
    const { data, setData, put, processing, errors , transform } = useForm({
        product_id: productBarcode.product_id || '',
        barcode: productBarcode.barcode || '',
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.product-barcodes.update', productBarcode.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Штрихкод обновлен',
                    description: 'Изменения успешно сохранены',
                    type: 'success',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
                <PageHeader title="Редактировать штрихкод" description="Изменение данных штрихкода" />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <SelectRelation
                                    label="Товар"
                                    value={data.product_id}
                                    onChange={(val) => setData('product_id', val)}
                                    options={products.map(p => ({ value: p.id, label: p.name }))}
                                    placeholder="Выберите товар"
                                    error={errors.product_id}
                                />

                                <FormField label="Штрихкод" error={errors.barcode}>
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
                                onSaveAndClose={handleSaveAndClose}
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
