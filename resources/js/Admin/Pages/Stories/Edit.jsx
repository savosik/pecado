import { useState, useRef } from 'react';
import { useForm, router } from '@inertiajs/react';
import { useSlugField } from '@/Admin/hooks/useSlugField';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack, Heading, Box } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import SlidesEditor from './Components/SlidesEditor';

export default function Edit({ story }) {
    const { data, setData, put, processing, errors, transform } = useForm({
        name: story.name || '',
        slug: story.slug || '',
        is_active: story.is_active ?? true,
        is_published: story.is_published ?? false,
        show_name: story.show_name ?? true,
        sort_order: story.sort_order || 0,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const [slides, setSlides] = useState(story.slides || []);

    const { handleSourceChange, handleSlugChange } = useSlugField({
        data, setData, sourceField: 'name', isEditing: true,
    });

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.stories.update', story.id));
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader
                title={`Редактировать сторис: ${story.name}`}
                description="Управление информацией и слайдами сториса"
            />

            <Stack gap={6}>
                {/* Story информация */}
                <Card.Root as="form" onSubmit={handleSubmit}>
                    <Card.Header>
                        <Heading size="md">Основная информация</Heading>
                    </Card.Header>

                    <Card.Body>
                        <Stack gap={4}>
                            <FormField label="Название" required error={errors.name}>
                                <Input
                                    value={data.name}
                                    onChange={(e) => handleSourceChange(e.target.value)}
                                    placeholder="Название сториса"
                                />
                            </FormField>

                            <FormField label="Slug" error={errors.slug}>
                                <Input
                                    value={data.slug}
                                    onChange={(e) => handleSlugChange(e.target.value)}
                                    placeholder="URL-адрес (автоматически)"
                                />
                            </FormField>

                            <FormField label="Активность" error={errors.is_active}>
                                <Switch
                                    checked={data.is_active}
                                    onCheckedChange={(e) => setData('is_active', e.checked)}
                                >
                                    Активен
                                </Switch>
                            </FormField>

                            <FormField label="Публикация" error={errors.is_published}>
                                <Switch
                                    checked={data.is_published}
                                    onCheckedChange={(e) => setData('is_published', e.checked)}
                                >
                                    Опубликован
                                </Switch>
                            </FormField>

                            <FormField label="Показывать название" error={errors.show_name}>
                                <Switch
                                    checked={data.show_name}
                                    onCheckedChange={(e) => setData('show_name', e.checked)}
                                >
                                    Отображать название на превью
                                </Switch>
                            </FormField>

                            <FormField label="Порядок сортировки" error={errors.sort_order}>
                                <Input
                                    type="number"
                                    value={data.sort_order}
                                    onChange={(e) => setData('sort_order', parseInt(e.target.value) || 0)}
                                />
                            </FormField>
                        </Stack>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            isLoading={processing}
                            onSaveAndClose={handleSaveAndClose}
                            cancelHref={route('admin.stories.index')}
                        />
                    </Card.Footer>
                </Card.Root>

                {/* Slides управление */}
                <SlidesEditor
                    story={story}
                    slides={slides}
                    onSlidesChange={setSlides}
                />
            </Stack>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;

