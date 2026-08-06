import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    Box,
    Card,
    Stack,
    NativeSelect,
    Text,
    VStack,
} from '@chakra-ui/react';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { ProductSelector } from '@/Admin/Components/ProductSelector';
import { MultipleImageUploader } from '@/Admin/Components/MultipleImageUploader';
import { Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { DefectDescriptionField } from '@/Wms/Components/DefectDescriptionField';
import { DefectStockWarning } from '@/Wms/Components/DefectStockWarning';
import { QuantityStepper } from '@/Wms/Components/QuantityStepper';

export default function DefectsCreate() {
    // prefill приходит из отчёта «Не закрыто партиями»: товар и склад уже известны,
    // кладовщику остаётся описать дефект и приложить фото.
    const { warehouses, defectTypes = [], prefill = null } = usePage().props;

    const { data, setData, post, processing, errors } = useForm({
        product_id: prefill?.product?.id ?? null,
        warehouse_id: prefill?.warehouse_id
            ? String(prefill.warehouse_id)
            : (warehouses.length === 1 ? String(warehouses[0].id) : ''),
        defect_description: '',
        quantity: 1,
        photos: [],
    });

    // ProductSelector работает с объектом товара, а на бэк уходит только id,
    // поэтому объект держим вне формы — иначе он поедет в запрос лишним полем.
    const [selectedProduct, setSelectedProduct] = useState(prefill?.product ?? null);

    const handleSubmit = (event) => {
        event.preventDefault();
        post('/wms/defects', { forceFormData: true });
    };

    return (
        <>
            <Head title="Новая партия некондиции — Склад" />
            <PageHeader
                title="Завести партию некондиции"
                description="Одна партия — один вид дефекта. Если дефекты разные, заведите отдельные партии."
            />

            <form onSubmit={handleSubmit}>
                <VStack gap={4} align="stretch" maxW="4xl">
                    <Card.Root>
                        <Card.Body>
                            <VStack gap={4} align="stretch">
                                {/* Без Field required: Chakra навесил бы HTML required
                                    на внутренний input поиска ProductSelector, а он после
                                    выбора товара пустеет — браузер блокировал бы отправку,
                                    хотя товар выбран. Обязательность держит бэк + guard ниже. */}
                                <Field
                                    label={<>Товар <Text as="span" color="red.500">*</Text></>}
                                    invalid={!!errors.product_id}
                                    errorText={errors.product_id}
                                    helperText="Найдите по названию, артикулу или отсканируйте штрихкод"
                                >
                                    <ProductSelector
                                        mode="single"
                                        searchRoute="wms.defects.search-products"
                                        // Фокус сразу в поиск: к рабочему месту подключён
                                        // сканер ШК — он печатает в активное поле и жмёт Enter.
                                        autoFocus={!prefill?.product}
                                        value={selectedProduct}
                                        onChange={(product) => {
                                            setSelectedProduct(product);
                                            setData('product_id', product?.id ?? null);
                                        }}
                                        error={errors.product_id}
                                    />
                                </Field>

                                {warehouses.length === 0 ? (
                                    <Box
                                        borderWidth="1px"
                                        borderColor="border"
                                        borderRadius="md"
                                        p={3}
                                    >
                                        <Text fontSize="sm" color="fg.muted">
                                            Склад некондиции не заведён. Обратитесь к администратору — склад
                                            отмечается флагом «Склад некондиции» в разделе «Склады».
                                        </Text>
                                    </Box>
                                ) : (
                                    <Field
                                        label="Склад"
                                        required
                                        invalid={!!errors.warehouse_id}
                                        errorText={errors.warehouse_id}
                                    >
                                        <NativeSelect.Root>
                                            <NativeSelect.Field
                                                value={data.warehouse_id}
                                                onChange={(event) => setData('warehouse_id', event.target.value)}
                                            >
                                                <option value="">Выберите склад</option>
                                                {warehouses.map((warehouse) => (
                                                    <option key={warehouse.id} value={warehouse.id}>
                                                        {warehouse.name}
                                                    </option>
                                                ))}
                                            </NativeSelect.Field>
                                            <NativeSelect.Indicator />
                                        </NativeSelect.Root>
                                    </Field>
                                )}

                                <Field
                                    label="Описание дефекта"
                                    required
                                    invalid={!!errors.defect_description}
                                    errorText={errors.defect_description}
                                >
                                    <DefectDescriptionField
                                        value={data.defect_description}
                                        onChange={(text) => setData('defect_description', text)}
                                        types={defectTypes}
                                        error={errors.defect_description}
                                    />
                                </Field>

                                <Field
                                    label="Количество, шт"
                                    required
                                    invalid={!!errors.quantity}
                                    errorText={errors.quantity}
                                    helperText="Столько единиц с этим же дефектом"
                                >
                                    <QuantityStepper
                                        value={data.quantity}
                                        onChange={(next) => setData('quantity', next)}
                                        min={1}
                                        max={10000}
                                    />
                                </Field>

                                <DefectStockWarning
                                    product={selectedProduct}
                                    warehouseId={data.warehouse_id}
                                    quantity={data.quantity}
                                />
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root>
                        <Card.Body>
                            <MultipleImageUploader
                                name="photos"
                                label="Фотографии дефекта"
                                value={data.photos}
                                onChange={(files) => setData('photos', files)}
                                error={errors.photos || errors['photos.0']}
                                maxFiles={10}
                                maxSize={20}
                                capture="environment"
                                webcam
                            />
                            <Text fontSize="xs" color="fg.muted" mt={2}>
                                Снимите товар веб-камерой или загрузите файлы с диска.
                                Фотографии увидит клиент на сайте — снимайте так, чтобы дефект был понятен.
                            </Text>
                        </Card.Body>
                    </Card.Root>

                    <Stack direction={{ base: 'column-reverse', sm: 'row' }} gap={2}>
                        <Button
                            variant="outline"
                            type="button"
                            h="48px"
                            onClick={() => window.history.back()}
                        >
                            Отмена
                        </Button>
                        <Button
                            type="submit"
                            h="48px"
                            flex={{ base: 'none', sm: '0 0 auto' }}
                            loading={processing}
                            disabled={warehouses.length === 0}
                        >
                            Завести партию
                        </Button>
                    </Stack>
                </VStack>
            </form>
        </>
    );
}

DefectsCreate.layout = (page) => <WmsLayout>{page}</WmsLayout>;
