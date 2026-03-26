import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ProductSelector } from '@/Admin/Components';
import { Box, Card, Input, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ productBarcode, currentProduct }) {
    const { data, setData, put, processing, errors , transform } = useForm({
        product_id: productBarcode.product_id || '',
        barcode: productBarcode.barcode || '',
    });

    const [selectedProduct, setSelectedProduct] = useState(currentProduct || null);
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
                                <FormField label="Товар" error={errors.product_id}>
                                    <ProductSelector
                                        mode="single"
                                        value={selectedProduct}
                                        onChange={(product) => {
                                            setSelectedProduct(product);
                                            setData('product_id', product ? product.id : '');
                                        }}
                                        error={errors.product_id}
                                    />
                                </FormField>

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
