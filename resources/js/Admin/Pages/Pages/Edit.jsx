import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, FileUploader, MarkdownEditor } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid, Textarea, Image, Box } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';
import { useState } from 'react';

export default function Edit({ page }) {
    const { data, setData, post, processing, errors } = useForm({
        title: page.title || '',
        slug: page.slug || '',
        content: page.content || '',
        meta_title: page.meta_title || '',
        meta_description: page.meta_description || '',
        main_image: null,
        _method: 'PUT',
    });

    const [autoSlug, setAutoSlug] = useState(false);

    const handleTitleChange = (e) => {
        const title = e.target.value;
        setData('title', title);

        if (autoSlug) {
            const slug = title
                .toLowerCase()
                .replace(/[^a-zа-я0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .trim();
            setData('slug', slug);
        }
    };

    const handleSlugChange = (e) => {
        setAutoSlug(false);
        setData('slug', e.target.value);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.pages.update', page.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Страница успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при обновлении страницы',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    return (
        <>
            <PageHeader title={`Редактировать страницу: ${page.title}`} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Заголовок *" error={errors.title} required>
                                    <Input
                                        value={data.title}
                                        onChange={handleTitleChange}
                                        placeholder="Введите заголовок страницы"
                                    />
                                </FormField>

                                <FormField label="Slug *" error={errors.slug} required>
                                    <Input
                                        value={data.slug}
                                        onChange={handleSlugChange}
                                        placeholder="url-slug-stranicy"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Содержимое *" error={errors.content} required>
                                <MarkdownEditor
                                    value={data.content}
                                    onChange={(value) => setData('content', value)}
                                    placeholder="Введите содержимое страницы..."
                                    context="page content"
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Meta Title" error={errors.meta_title}>
                                    <Input
                                        value={data.meta_title}
                                        onChange={(e) => setData('meta_title', e.target.value)}
                                        placeholder="SEO заголовок"
                                    />
                                </FormField>

                                <FormField label="Meta Description" error={errors.meta_description}>
                                    <Textarea
                                        value={data.meta_description}
                                        onChange={(e) => setData('meta_description', e.target.value)}
                                        placeholder="SEO описание"
                                        rows={3}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Главное изображение" error={errors.main_image}>
                                {page.main_image && !data.main_image && (
                                    <Box mb={4}>
                                        <Image
                                            src={page.main_image}
                                            alt={page.title}
                                            maxH="200px"
                                            objectFit="contain"
                                            borderRadius="md"
                                        />
                                    </Box>
                                )}
                                <FileUploader
                                    value={data.main_image}
                                    onChange={(file) => setData('main_image', file)}
                                    accept="image/*"
                                />
                            </FormField>

                            <FormActions
                                submitLabel="Сохранить изменения"
                                onCancel={() => window.history.back()}
                                processing={processing}
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
