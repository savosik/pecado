import { useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, TagSelector, SelectRelation, MarkdownEditor } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack, Tabs } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';
import { LuFileText, LuAlignLeft, LuImage, LuTag, LuSearch } from 'react-icons/lu';

export default function Create({ brands, categories }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        parent_id: '',
        category: 'other',
        external_id: '',
        slug: '',
        short_description: '',
        description: '',
        meta_title: '',
        meta_description: '',
        logo: null,
        tags: [],
    });

    const tabErrors = useMemo(() => ({
        general: ['name', 'parent_id', 'category', 'external_id', 'slug'].some(field => errors[field]),
        descriptions: ['short_description', 'description'].some(field => errors[field]),
        seo: ['meta_title', 'meta_description'].some(field => errors[field]),
        media: ['logo', 'tags'].some(field => errors[field]),
    }), [errors]);

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.brands.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Бренд создан',
                    description: 'Бренд успешно добавлен в систему',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось создать бренд. Проверьте правильность заполнения полей.',
                    type: 'error',
                });
            },
        });
    };

    return (
        <>
                <PageHeader
                    title="Создать бренд"
                    description="Добавление нового бренда"
                />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Tabs.Root defaultValue="general" colorPalette="blue">
                                <Tabs.List>
                                    <Tabs.Trigger value="general">
                                        <LuFileText /> Основная информация
                                        {tabErrors.general && (
                                            <Box as="span" color="red.500" ml={2} fontWeight="bold">
                                                ⚠️
                                            </Box>
                                        )}
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="descriptions">
                                        <LuAlignLeft /> Описания
                                        {tabErrors.descriptions && (
                                            <Box as="span" color="red.500" ml={2} fontWeight="bold">
                                                ⚠️
                                            </Box>
                                        )}
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="seo">
                                        <LuSearch /> SEO
                                        {tabErrors.seo && (
                                            <Box as="span" color="red.500" ml={2} fontWeight="bold">
                                                ⚠️
                                            </Box>
                                        )}
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="media">
                                        <LuImage /> Медиа и теги
                                        {tabErrors.media && (
                                            <Box as="span" color="red.500" ml={2} fontWeight="bold">
                                                ⚠️
                                            </Box>
                                        )}
                                    </Tabs.Trigger>
                                </Tabs.List>

                                <Tabs.Content value="general">
                                    <Stack gap={6} mt={6}>
                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                            <FormField
                                                label="Название бренда"
                                                required
                                                error={errors.name}
                                            >
                                                <Input
                                                    value={data.name}
                                                    onChange={(e) => setData('name', e.target.value)}
                                                    placeholder="Введите название бренда"
                                                />
                                            </FormField>

                                            <SelectRelation
                                                label="Родительский бренд"
                                                value={data.parent_id}
                                                onChange={(value) => setData('parent_id', value)}
                                                options={[
                                                    { value: '', label: 'Без родителя' },
                                                    ...brands.map(b => ({ value: b.id, label: b.name }))
                                                ]}
                                                placeholder="Выберите родительский бренд"
                                                error={errors.parent_id}
                                            />

                                            <SelectRelation
                                                label="Категория бренда"
                                                required
                                                value={data.category}
                                                onChange={(value) => setData('category', value)}
                                                options={categories}
                                                placeholder="Выберите категорию"
                                                error={errors.category}
                                            />

                                            <FormField
                                                label="Slug (URL)"
                                                error={errors.slug}
                                                helperText="Оставьте пустым для автогенерации"
                                            >
                                                <Input
                                                    value={data.slug}
                                                    onChange={(e) => setData('slug', e.target.value)}
                                                    placeholder="slug-brenda"
                                                />
                                            </FormField>

                                            <FormField
                                                label="External ID"
                                                error={errors.external_id}
                                                helperText="Внешний идентификатор для интеграций"
                                            >
                                                <Input
                                                    value={data.external_id}
                                                    onChange={(e) => setData('external_id', e.target.value)}
                                                    placeholder="Внешний идентификатор"
                                                />
                                            </FormField>
                                        </SimpleGrid>
                                    </Stack>
                                </Tabs.Content>

                                <Tabs.Content value="descriptions">
                                    <Stack gap={6} mt={6}>
                                        <FormField
                                            label="Краткое описание"
                                            error={errors.short_description}
                                        >
                                            <MarkdownEditor
                                                value={data.short_description}
                                                onChange={(val) => setData('short_description', val)}
                                                placeholder="Введите краткое описание"
                                                minHeight="100px"
                                                context={`Бренд: ${data.name} (Кратко)`}
                                            />
                                        </FormField>

                                        <FormField
                                            label="Полное описание"
                                            error={errors.description}
                                        >
                                            <MarkdownEditor
                                                value={data.description}
                                                onChange={(val) => setData('description', val)}
                                                placeholder="Введите полное описание"
                                                minHeight="250px"
                                                context={`Бренд: ${data.name}`}
                                            />
                                        </FormField>
                                    </Stack>
                                </Tabs.Content>

                                <Tabs.Content value="seo">
                                    <Stack gap={6} mt={6}>
                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                            <FormField
                                                label="Meta Title"
                                                error={errors.meta_title}
                                            >
                                                <Input
                                                    value={data.meta_title}
                                                    onChange={(e) => setData('meta_title', e.target.value)}
                                                    placeholder="SEO заголовок"
                                                />
                                            </FormField>

                                            <FormField
                                                label="Meta Description"
                                                error={errors.meta_description}
                                            >
                                                <Input
                                                    value={data.meta_description}
                                                    onChange={(e) => setData('meta_description', e.target.value)}
                                                    placeholder="SEO описание"
                                                />
                                            </FormField>
                                        </SimpleGrid>
                                    </Stack>
                                </Tabs.Content>

                                <Tabs.Content value="media">
                                    <Stack gap={6} mt={6}>
                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={6}>
                                            <Box>
                                                <Box fontSize="lg" fontWeight="semibold" mb={4}>
                                                    <LuImage style={{ display: 'inline', marginRight: '8px' }} />
                                                    Логотип бренда
                                                </Box>
                                                <ImageUploader
                                                    onChange={(file) => setData('logo', file)}
                                                    maxPreviewWidth="150px"
                                                    aspectRatio="1"
                                                    placeholder="Загрузить логотип"
                                                    error={errors.logo}
                                                />
                                            </Box>

                                            <Box>
                                                <Box fontSize="lg" fontWeight="semibold" mb={4}>
                                                    <LuTag style={{ display: 'inline', marginRight: '8px' }} />
                                                    Теги
                                                </Box>
                                                <TagSelector
                                                    value={data.tags}
                                                    onChange={(tags) => setData('tags', tags)}
                                                    placeholder="Введите теги..."
                                                />
                                            </Box>
                                        </SimpleGrid>
                                    </Stack>
                                </Tabs.Content>
                            </Tabs.Root>
                        </Card.Body>

                        <Card.Footer>
                            <FormActions
                                loading={processing}
                                onCancel={() => window.history.back()}
                                submitLabel="Создать бренд"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
