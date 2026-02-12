import { useRef } from 'react';
import { useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, ProductSelector, MarkdownEditor } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid, Flex, Text, IconButton } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';

export default function Edit({ product_selection }) {
    const { data, setData, post, processing, errors } = useForm({
        _method: 'PUT',
        name: product_selection.name || '',
        meta_title: product_selection.meta_title || '',
        meta_description: product_selection.meta_description || '',
        description: product_selection.description || '',
        show_on_home: product_selection.show_on_home || false,
        products: product_selection.products || [],
        featured_ids: (product_selection.products || []).filter(p => p.featured).map(p => p.id),
        desktop_image: null,
        mobile_image: null,
    });

    const closeAfterSaveRef = useRef(false);



    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;

        const formData = new FormData();
        formData.append('_method', 'PUT');
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

        router.post(route('admin.product-selections.update', product_selection.id), formData, {
            forceFormData: true,
            onSuccess: () => {
                toaster.create({
                    title: 'Подборка обновлена',
                    description: 'Информация о подборке успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось обновить подборку. Проверьте правильность заполнения полей.',
                    type: 'error',
                });
            },
        });
    };

    const handleRemoveDesktopImage = () => {
        if (!product_selection.desktop_image_id) return;

        router.delete(route('admin.product-selections.media.delete', { product_selection: product_selection.id }), {
            data: { media_id: product_selection.desktop_image_id },
            onSuccess: () => {
                toaster.create({
                    title: 'Изображение удалено',
                    type: 'success',
                });
                router.reload({ only: ['product_selection'] });
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

    const handleRemoveMobileImage = () => {
        if (!product_selection.mobile_image_id) return;

        router.delete(route('admin.product-selections.media.delete', { product_selection: product_selection.id }), {
            data: { media_id: product_selection.mobile_image_id },
            onSuccess: () => {
                toaster.create({
                    title: 'Изображение удалено',
                    type: 'success',
                });
                router.reload({ only: ['product_selection'] });
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

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader
                title={`Редактирование: ${product_selection.name}`}
                description="Изменение информации о подборке товаров"
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
                                        existingUrl={product_selection.desktop_image_url}
                                        onRemoveExisting={handleRemoveDesktopImage}
                                    />
                                </FormField>

                                <FormField label="Изображение для Mobile" error={errors.mobile_image}>
                                    <ImageUploader
                                        onChange={(file) => setData('mobile_image', file)}
                                        error={errors.mobile_image}
                                        maxSize={10}
                                        existingUrl={product_selection.mobile_image_url}
                                        onRemoveExisting={handleRemoveMobileImage}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <Box>
                                <FormField label="Привязанные товары" error={errors.product_ids}>
                                    <ProductSelector
                                        value={data.products}
                                        onChange={(products) => {
                                            setData('products', products);
                                            // Убираем из featured_ids те, что больше не в списке
                                            const ids = products.map(p => p.id);
                                            setData('featured_ids', data.featured_ids.filter(id => ids.includes(id)));
                                        }}
                                        error={errors.product_ids}
                                    />
                                </FormField>

                                {data.products.length > 0 && (
                                    <Box mt="3" p="3" bg="gray.50" borderRadius="md" _dark={{ bg: 'gray.800' }}>
                                        <Text fontSize="sm" fontWeight="600" mb="2" color="gray.600">
                                            Показывать на главной (в табах):
                                        </Text>
                                        <Stack gap="1">
                                            {data.products.map((product) => (
                                                <Checkbox
                                                    key={product.id}
                                                    size="sm"
                                                    checked={data.featured_ids.includes(product.id)}
                                                    onCheckedChange={(e) => {
                                                        const checked = e.checked;
                                                        setData('featured_ids',
                                                            checked
                                                                ? [...data.featured_ids, product.id]
                                                                : data.featured_ids.filter(id => id !== product.id)
                                                        );
                                                    }}
                                                >
                                                    {product.name}
                                                </Checkbox>
                                            ))}
                                        </Stack>
                                    </Box>
                                )}
                            </Box>
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
