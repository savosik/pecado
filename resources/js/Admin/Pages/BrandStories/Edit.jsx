import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { useSlugField } from '@/Admin/hooks/useSlugField';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, TagSelector, ContentMediaFields, EditorJsEditor, RegionSelector } from '@/Admin/Components';
import { Card, Input, Textarea, Stack, SimpleGrid, NativeSelectRoot, NativeSelectField } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ brandStory, brands, regions = [] }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        title: brandStory.title || '',
        slug: brandStory.slug || '',
        short_description: brandStory.short_description || '',
        detailed_description: brandStory.detailed_description || '',
        is_published: brandStory.is_published ?? true,
        published_at: brandStory.published_at ? brandStory.published_at.slice(0, 16) : '',
        meta_title: brandStory.meta_title || '',
        meta_description: brandStory.meta_description || '',
        brand_id: brandStory.brand_id || '',
        tags: brandStory.tag_list || [],
        list_item: null,
        detail_desktop: null,
        detail_mobile: null,
        region_ids: brandStory.region_ids || [],
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
        post(route('admin.brand-stories.update', brandStory.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Статья о бренде успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при обновлении статьи о бренде',
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
            <PageHeader title={`Редактировать статью о бренде: ${brandStory.title}`} />

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

                            <FormField label="Бренд" error={errors.brand_id} required>
                                <NativeSelectRoot>
                                    <NativeSelectField
                                        placeholder="Выберите бренд"
                                        value={data.brand_id}
                                        onChange={(e) => setData('brand_id', e.target.value)}
                                    >
                                        {brands.map((brand) => (
                                            <option key={brand.id} value={brand.id}>
                                                {brand.name}
                                            </option>
                                        ))}
                                    </NativeSelectField>
                                </NativeSelectRoot>
                            </FormField>

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

                            <FormField label="Краткое описание" error={errors.short_description} required>
                                <Textarea
                                    value={data.short_description}
                                    onChange={(e) => setData('short_description', e.target.value)}
                                    rows={3}
                                />
                            </FormField>

                            <FormField label="Полное описание" error={errors.detailed_description} required>
                                <EditorJsEditor
                                    value={data.detailed_description}
                                    onChange={(value) => setData('detailed_description', value)}
                                    placeholder="Введите полное описание статьи о бренде..."
                                    context="brand story content"
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

                            <FormField label="Регионы" error={errors.region_ids} helperText="Если не выбран ни один регион — контент показывается всем">
                                <RegionSelector
                                    regions={regions}
                                    value={data.region_ids}
                                    onChange={(value) => setData('region_ids', value)}
                                />
                            </FormField>

                            <ContentMediaFields
                                data={data}
                                setData={setData}
                                errors={errors}
                                existing={{
                                    list_image: brandStory.list_image,
                                    detail_desktop_image: brandStory.detail_desktop_image,
                                    detail_mobile_image: brandStory.detail_mobile_image,
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
