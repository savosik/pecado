import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { useSlugField } from '@/Admin/hooks/useSlugField';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, TagSelector, ContentMediaFields, MarkdownEditor } from '@/Admin/Components';
import { Card, Input, Textarea, Stack, SimpleGrid } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ news }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        title: news.title || '',
        slug: news.slug || '',
        detailed_description: news.detailed_description || '',
        is_published: news.is_published ?? true,
        published_at: news.published_at ? news.published_at.slice(0, 16) : '',
        meta_title: news.meta_title || '',
        meta_description: news.meta_description || '',
        tags: news.tag_list || [],
        list_item: null,
        detail_desktop: null,
        detail_mobile: null,
        _method: 'PUT',
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const { handleSourceChange, handleSlugChange } = useSlugField({
        data, setData, sourceField: 'title', isEditing: true,
    });

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.news.update', news.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Новость успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при обновлении новости',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader title={`Редактировать новость: ${news.title}`} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Заголовок" error={errors.title} required>
                                    <Input
                                        value={data.title}
                                        onChange={(e) => handleSourceChange(e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Slug" error={errors.slug} required>
                                    <Input
                                        value={data.slug}
                                        onChange={(e) => handleSlugChange(e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Опубликован" error={errors.is_published}>
                                    <Switch
                                        checked={data.is_published}
                                        onCheckedChange={(e) => setData('is_published', e.checked)}
                                    />
                                </FormField>

                                <FormField label="Дата публикации" error={errors.published_at}>
                                    <Input
                                        type="datetime-local"
                                        value={data.published_at}
                                        onChange={(e) => setData('published_at', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Полное описание" error={errors.detailed_description} required>
                                <MarkdownEditor
                                    value={data.detailed_description}
                                    onChange={(value) => setData('detailed_description', value)}
                                    placeholder="Введите полное описание новости..."
                                    context="news content"
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Meta заголовок" error={errors.meta_title}>
                                    <Input
                                        value={data.meta_title}
                                        onChange={(e) => setData('meta_title', e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Meta описание" error={errors.meta_description}>
                                    <Textarea
                                        value={data.meta_description}
                                        onChange={(e) => setData('meta_description', e.target.value)}
                                        rows={3}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Теги" error={errors.tags}>
                                <TagSelector
                                    value={data.tags}
                                    onChange={(value) => setData('tags', value)}
                                />
                            </FormField>

                            <ContentMediaFields
                                data={data}
                                setData={setData}
                                errors={errors}
                                existing={{
                                    list_image: news.list_image,
                                    detail_desktop_image: news.detail_desktop_image,
                                    detail_mobile_image: news.detail_mobile_image,
                                }}
                            />

                            <FormActions
                                onSaveAndClose={handleSaveAndClose}
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
