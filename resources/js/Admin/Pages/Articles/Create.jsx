import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, TagSelector, FileUploader } from '@/Admin/Components';
import { Card, Input, Textarea, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        slug: '',
        short_description: '',
        detailed_description: '',
        meta_title: '',
        meta_description: '',
        tags: [],
        main_image: null,
    });

    // Auto-generate slug from title
    const handleTitleChange = (value) => {
        setData({
            ...data,
            title: value,
            slug: value
                .toLowerCase()
                .replace(/[^a-z0-9а-яё\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .trim(),
        });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.articles.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Статья успешно создана',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при создании статьи',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    return (
        <>
            <PageHeader title="Создать статью" />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Заголовок *" error={errors.title} required>
                                    <Input
                                        value={data.title}
                                        onChange={(e) => handleTitleChange(e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Slug *" error={errors.slug} required>
                                    <Input
                                        value={data.slug}
                                        onChange={(e) => setData('slug', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Краткое описание *" error={errors.short_description} required>
                                <Textarea
                                    value={data.short_description}
                                    onChange={(e) => setData('short_description', e.target.value)}
                                    rows={3}
                                />
                            </FormField>

                            <FormField label="Полное описание *" error={errors.detailed_description} required>
                                <Textarea
                                    value={data.detailed_description}
                                    onChange={(e) => setData('detailed_description', e.target.value)}
                                    rows={8}
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

                            <FormField label="Изображение" error={errors.main_image}>
                                <FileUploader
                                    value={data.main_image}
                                    onChange={(file) => setData('main_image', file)}
                                    accept="image/*"
                                />
                            </FormField>

                            <FormActions
                                submitLabel="Создать статью"
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

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
