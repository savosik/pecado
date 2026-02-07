import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, MultipleImageUploader, ProductSelector } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        meta_title: '',
        meta_description: '',
        description: '',
        products: [],
        main_image: null,
        images: [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();

        const formData = new FormData();
        formData.append('name', data.name);
        if (data.meta_title) formData.append('meta_title', data.meta_title);
        if (data.meta_description) formData.append('meta_description', data.meta_description);
        if (data.description) formData.append('description', data.description);

        // Привязка товаров
        data.products.forEach((product, index) => {
            formData.append(`product_ids[${index}]`, product.id);
        });

        // Главное изображение
        if (data.main_image) {
            formData.append('main_image', data.main_image);
        }

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

    return (
        <>
                <PageHeader title="Создать акцию" description="Добавление новой рекламной акции" />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="Название *" error={errors.name} required>
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

                                <ImageUploader
                                    label="Главное изображение"
                                    value={data.main_image}
                                    onChange={(file) => setData('main_image', file)}
                                    error={errors.main_image}
                                    maxSize={10}
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
                                loading={processing}
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
