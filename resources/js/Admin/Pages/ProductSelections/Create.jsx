import { useRef } from 'react';
import { useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, ProductSelector, MarkdownEditor } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid, Text } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';
import { Switch } from '@/components/ui/switch';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        meta_title: '',
        meta_description: '',
        description: '',
        show_on_home: false,
        products: [],
        featured_ids: [],
        desktop_image: null,
        mobile_image: null,
    });

    const closeAfterSaveRef = useRef(false);



    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;

        const formData = new FormData();
        formData.append('_close', shouldClose ? '1' : '0');
        formData.append('name', data.name);
        formData.append('show_on_home', data.show_on_home ? '1' : '0');
        if (data.meta_title) formData.append('meta_title', data.meta_title);
        if (data.meta_description) formData.append('meta_description', data.meta_description);
        if (data.description) formData.append('description', data.description);

        // Привязка товаров
        data.products.forEach((product, index) => {
            formData.append(`product_ids[${index}]`, product.id);
        });

        // Featured товары
        data.featured_ids.forEach((id, index) => {
            formData.append(`featured_ids[${index}]`, id);
        });

        // Изображения
        if (data.desktop_image) {
            formData.append('desktop_image', data.desktop_image);
        }
        if (data.mobile_image) {
            formData.append('mobile_image', data.mobile_image);
        }

        router.post(route('admin.product-selections.store'), formData, {
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

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader title="Создать подборку товаров" description="Добавление новой подборки для отображения на сайте" />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Название" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Например: Новинки сезона"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <Switch
                                checked={data.show_on_home}
                                onCheckedChange={(e) => setData('show_on_home', e.checked)}
                            >
                                Показывать на главной (в табах)
                            </Switch>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
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
                                <MarkdownEditor
                                    value={data.description}
                                    onChange={(value) => setData('description', value)}
                                    placeholder="Подробное описание подборки..."
                                    context="product selection description"
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Изображение для Desktop" error={errors.desktop_image}>
                                    <ImageUploader
                                        onChange={(file) => setData('desktop_image', file)}
                                        error={errors.desktop_image}
                                        maxSize={10}
                                    />
                                </FormField>

                                <FormField label="Изображение для Mobile" error={errors.mobile_image}>
                                    <ImageUploader
                                        onChange={(file) => setData('mobile_image', file)}
                                        error={errors.mobile_image}
                                        maxSize={10}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <Box>
                                <FormField label="Привязанные товары" error={errors.product_ids}>
                                    <ProductSelector
                                        value={data.products}
                                        onChange={(products) => {
                                            setData('products', products);
                                            const ids = products.map(p => p.id);
                                            setData('featured_ids', data.featured_ids.filter(id => ids.includes(id)));
                                        }}
                                        error={errors.product_ids}
                                        renderItemActions={(product) => (
                                            <Switch
                                                size="sm"
                                                checked={data.featured_ids.includes(product.id)}
                                                onCheckedChange={(e) => {
                                                    setData('featured_ids',
                                                        e.checked
                                                            ? [...data.featured_ids, product.id]
                                                            : data.featured_ids.filter(id => id !== product.id)
                                                    );
                                                }}
                                            >
                                                На главной
                                            </Switch>
                                        )}
                                    />
                                </FormField>
                            </Box>
                        </Stack>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Создать подборку"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
