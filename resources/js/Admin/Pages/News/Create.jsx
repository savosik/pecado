import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { useSlugField } from '@/Admin/hooks/useSlugField';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, TagSelector, ContentMediaFields, MarkdownEditor } from '@/Admin/Components';
import { Card, Input, Textarea, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        title: '',
        slug: '',
        detailed_description: '',
        meta_title: '',
        meta_description: '',
        tags: [],
        list_item: null,
        detail_desktop: null,
        detail_mobile: null,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const { handleSourceChange, handleSlugChange } = useSlugField({
        data, setData, sourceField: 'title',
    });

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.news.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Новость успешно создана',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при создании новости',
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
            <PageHeader title="Создать новость" />

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
                            />

                            <FormActions
                                onSaveAndClose={handleSaveAndClose}
                                submitLabel="Создать новость"
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

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
