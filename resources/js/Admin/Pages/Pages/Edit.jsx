import { useForm } from '@inertiajs/react';
import { useSlugField } from '@/Admin/hooks/useSlugField';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ContentMediaFields, MarkdownEditor } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid, Textarea } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ page }) {
    const { data, setData, post, processing, errors } = useForm({
        title: page.title || '',
        slug: page.slug || '',
        content: page.content || '',
        meta_title: page.meta_title || '',
        meta_description: page.meta_description || '',
        list_item: null,
        detail_desktop: null,
        detail_mobile: null,
        _method: 'PUT',
    });

    const { handleSourceChange, handleSlugChange } = useSlugField({
        data, setData, sourceField: 'title', isEditing: true,
    });

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
                                <FormField label="Заголовок" error={errors.title} required>
                                    <Input
                                        value={data.title}
                                        onChange={(e) => handleSourceChange(e.target.value)}
                                        placeholder="Введите заголовок страницы"
                                    />
                                </FormField>

                                <FormField label="Slug" error={errors.slug} required>
                                    <Input
                                        value={data.slug}
                                        onChange={(e) => handleSlugChange(e.target.value)}
                                        placeholder="url-slug-stranicy"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Содержимое" error={errors.content} required>
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

                            <ContentMediaFields
                                data={data}
                                setData={setData}
                                errors={errors}
                                existing={{
                                    list_image: page.list_image,
                                    detail_desktop_image: page.detail_desktop_image,
                                    detail_mobile_image: page.detail_mobile_image,
                                }}
                            />

                            <FormActions
                                submitLabel="Сохранить изменения"
                                onCancel={() => window.history.back()}
                                isLoading={processing}
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
