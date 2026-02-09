import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ContentMediaFields, MultipleImageUploader, ProductSelector } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        meta_title: '',
        meta_description: '',
        description: '',
        products: [],
        list_item: null,
        detail_desktop: null,
        detail_mobile: null,
        images: [],
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;

        const formData = new FormData();
        formData.append('name', data.name);
        if (data.meta_title) formData.append('meta_title', data.meta_title);
        if (data.meta_description) formData.append('meta_description', data.meta_description);
        if (data.description) formData.append('description', data.description);

        // Привязка товаров
        data.products.forEach((product, index) => {
            formData.append(`product_ids[${index}]`, product.id);
        });

        // Изображения контента
        if (data.list_item) formData.append('list_item', data.list_item);
        if (data.detail_desktop) formData.append('detail_desktop', data.detail_desktop);
        if (data.detail_mobile) formData.append('detail_mobile', data.detail_mobile);

        // Галерея
        data.images.forEach((image, index) => {
            formData.append(`images[${index}]`, image);
        });

        post(route('admin.promotions.store'), {
            data: formData,
            forceFormData: true,
            onSuccess: () => {
                toaster.create({
                    title: 'Акция создана',
                    description: 'Акция успешно добавлена в систему',
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
            <PageHeader title="Создать акцию" description="Добавление новой рекламной акции" />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Название" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Например: Летняя распродажа"
                                    />
                                </FormField>

                                <FormField label="Meta заголовок" error={errors.meta_title}>
                                    <Input
                                        value={data.meta_title}
                                        onChange={(e) => setData('meta_title', e.target.value)}
                                        placeholder="SEO заголовок"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Meta описание" error={errors.meta_description}>
                                <Textarea
                                    value={data.meta_description}
                                    onChange={(e) => setData('meta_description', e.target.value)}
                                    placeholder="SEO описание"
                                    rows={2}
                                />
                            </FormField>

                            <FormField label="Описание акции" error={errors.description}>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Подробное описание акции"
                                    rows={5}
                                />
                            </FormField>

                            <ContentMediaFields
                                data={data}
                                setData={setData}
                                errors={errors}
                            />

                            <MultipleImageUploader
                                label="Галерея изображений"
                                value={data.images}
                                onChange={(files) => setData('images', files)}
                                error={errors.images}
                                maxFiles={10}
                                maxSize={10}
                            />

                            <Box>
                                <FormField label="Привязанные товары" error={errors.product_ids}>
                                    <ProductSelector
                                        value={data.products}
                                        onChange={(products) => setData('products', products)}
                                        error={errors.product_ids}
                                    />
                                </FormField>
                            </Box>
                        </Stack>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            isLoading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Создать акцию"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
