import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField } from '@/Admin/Components';
import { Card, Input, Button, HStack, Stack, Heading, Box } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { LuSave, LuX } from 'react-icons/lu';
import { useEffect } from 'react';
import SlidesEditor from './Components/SlidesEditor';

export default function Edit({ story }) {
    const { data, setData, put, processing, errors } = useForm({
        name: story.name || '',
        slug: story.slug || '',
        is_active: story.is_active ?? true,
        sort_order: story.sort_order || 0,
    });

    const [slides, setSlides] = useState(story.slides || []);

    // Auto-generate slug from name
    useEffect(() => {
        if (data.name && !data.slug) {
            const slug = data.name
                .toLowerCase()
                .replace(/[^a-z0-9а-я]+/g, '-')
                .replace(/(^-|-$)/g, '');
            setData('slug', slug);
        }
    }, [data.name]);

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.stories.update', story.id));
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
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Название сториса"
                                />
                            </FormField>

                            <FormField label="Slug" error={errors.slug}>
                                <Input
                                    value={data.slug}
                                    onChange={(e) => setData('slug', e.target.value)}
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
                        <HStack gap={3}>
                            <Button
                                type="submit"
                                colorPalette="blue"
                                loading={processing}
                            >
                                <LuSave />
                                Сохранить
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.visit(route('admin.stories.index'))}
                            >
                                <LuX />
                                Отмена
                            </Button>
                        </HStack>
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
