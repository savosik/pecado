import { useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, MultipleImageUploader, ProductSelector } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ promotion }) {
    const { data, setData, post, processing, errors } = useForm({
        _method: 'PUT',
        name: promotion.name || '',
        meta_title: promotion.meta_title || '',
        meta_description: promotion.meta_description || '',
        description: promotion.description || '',
        products: promotion.products || [],
        main_image: null,
        images: [],
        delete_main_image: false,
        delete_images: [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();

        const formData = new FormData();
        formData.append('_method', 'PUT');
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
        if (data.delete_main_image) {
            formData.append('delete_main_image', '1');
        }

        // Галерея
        data.images.forEach((image, index) => {
            formData.append(`images[${index}]`, image);
        });

        // Удаление изображений из галереи
        data.delete_images.forEach((imageId, index) => {
            formData.append(`delete_images[${index}]`, imageId);
        });

        post(route('admin.promotions.update', promotion.id), {
            data: formData,
            forceFormData: true,
            onSuccess: () => {
                toaster.create({
                    title: 'Акция обновлена',
                    description: 'Информация об акции успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось обновить акцию. Проверьте правильность заполнения полей.',
                    type: 'error',
                });
            },
        });
    };

    const handleRemoveMainImage = () => {
        if (!promotion.main_image_id) return;

        router.delete(route('admin.promotions.media.delete', { promotion: promotion.id }), {
            data: { media_id: promotion.main_image_id },
            onSuccess: () => {
                toaster.create({
                    title: 'Изображение удалено',
                    type: 'success',
                });
                router.reload({ only: ['promotion'] });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось удалить изображение',
                    type: 'error',
                });
            }
        });
    };

    const handleRemoveGalleryImage = (mediaId) => {
        router.delete(route('admin.promotions.media.delete', { promotion: promotion.id }), {
            data: { media_id: mediaId },
            onSuccess: () => {
                toaster.create({
                    title: 'Изображение удалено',
                    type: 'success',
                });
                router.reload({ only: ['promotion'] });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось удалить изображение',
                    type: 'error',
                });
            }
        });
    };

    return (
        <>
                <PageHeader
                    title={`Редактирование: ${promotion.name}`}
                    description="Изменение информации об акции"
                />

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

                                <ImageUploader
                                    label="Главное изображение"
                                    onChange={(file) => setData('main_image', file)}
                                    error={errors.main_image}
                                    maxSize={10}
                                    currentImageUrl={promotion.main_image_url}
                                    onRemoveExisting={handleRemoveMainImage}
                                />

                                <MultipleImageUploader
                                    label="Галерея изображений"
                                    onChange={(files) => setData('images', files)}
                                    error={errors.images}
                                    maxFiles={10}
                                    maxSize={10}
                                    currentImages={promotion.gallery_images}
                                    onRemoveExisting={handleRemoveGalleryImage}
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
                                submitLabel="Сохранить изменения"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
