import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, ProductSelector } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        meta_title: '',
        meta_description: '',
        description: '',
        products: [],
        desktop_image: null,
        mobile_image: null,
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

        // Изображения
        if (data.desktop_image) {
            formData.append('desktop_image', data.desktop_image);
        }
        if (data.mobile_image) {
            formData.append('mobile_image', data.mobile_image);
        }

        post(route('admin.product-selections.store'), {
            data: formData,
            forceFormData: true,
            onSuccess: () => {
                toaster.create({
                    title: 'Подборка создана',
                    description: 'Подборка товаров успешно добавлена в систему',
                    type: 'success',
                });
            },
        });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { label: 'Главная', href: route('admin.dashboard') },
                { label: 'Подборки товаров', href: route('admin.product-selections.index') },
                { label: 'Создать' },
            ]}
        >
            <Box p={6}>
                <PageHeader title="Создать подборку товаров" description="Добавление новой подборки для отображения на сайте" />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="Название *" error={errors.name} required>
                                        <Input
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="Например: Новинки сезона"
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

                                <FormField label="Описание подборки" error={errors.description}>
                                    <Textarea
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="Подробное описание подборки"
                                        rows={4}
                                    />
                                </FormField>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <ImageUploader
                                        label="Изображение для Desktop"
                                        value={data.desktop_image}
                                        onChange={(file) => setData('desktop_image', file)}
                                        error={errors.desktop_image}
                                        maxSize={10}
                                    />

                                    <ImageUploader
                                        label="Изображение для Mobile"
                                        value={data.mobile_image}
                                        onChange={(file) => setData('mobile_image', file)}
                                        error={errors.mobile_image}
                                        maxSize={10}
                                    />
                                </SimpleGrid>

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
                                submitLabel="Создать подборку"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
            </Box>
        </AdminLayout>
    );
}
