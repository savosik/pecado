import { useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, TagSelector, SelectRelation, MarkdownEditor } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack, Tabs } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';
import { LuFileText, LuAlignLeft, LuImage, LuTag, LuSearch } from 'react-icons/lu';

export default function Create({ categories, availableAttributes }) {
    const attributeOptions = useMemo(() => availableAttributes.map(a => ({ value: a.id, label: a.name })), [availableAttributes]);
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        parent_id: '',
        external_id: '',
        short_description: '',
        description: '',
        meta_title: '',
        meta_description: '',
        icon: null,
        tags: [],
        attribute_ids: [],
    });

    // Определяем, в каких табах есть ошибки
    const tabErrors = useMemo(() => ({
        general: ['name', 'parent_id', 'external_id'].some(field => errors[field]),
        descriptions: ['short_description', 'description'].some(field => errors[field]),
        seo: ['meta_title', 'meta_description'].some(field => errors[field]),
        media: ['icon', 'tags'].some(field => errors[field]),
    }), [errors]);

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.categories.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Категория создана',
                    description: 'Категория успешно добавлена в систему',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось создать категорию. Проверьте правильность заполнения полей.',
                    type: 'error',
                });
            },
        });
    };

    return (
        <>
            <PageHeader
                title="Создать категорию"
                description="Добавление новой категории в каталог"
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

                            {/* Таб 1: Основная информация */}
                            <Tabs.Content value="general">
                                <Stack gap={6} mt={6}>
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField
                                            label="Название категории"
                                            required
                                            error={errors.name}
                                        >
                                            <Input
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                placeholder="Введите название категории"
                                            />
                                        </FormField>

                                        <SelectRelation
                                            label="Родительская категория"
                                            value={data.parent_id}
                                            onChange={(value) => setData('parent_id', value)}
                                            options={[
                                                { value: '', label: 'Без родителя (корневая)' },
                                                ...categories.map(c => ({ value: c.id, label: c.name }))
                                            ]}
                                            placeholder="Выберите родительскую категорию"
                                            error={errors.parent_id}
                                        />

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

                                    <Box mt={4}>
                                        <SelectRelation
                                            label="Атрибуты"
                                            value={data.attribute_ids}
                                            onChange={(value) => setData('attribute_ids', value)}
                                            options={attributeOptions}
                                            placeholder="Выберите атрибуты"
                                            multiple
                                            error={errors.attribute_ids}
                                        />
                                    </Box>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 2: Описания */}
                            <Tabs.Content value="descriptions">
                                <Stack gap={6} mt={6}>
                                    <FormField
                                        label="Краткое описание"
                                        error={errors.short_description}
                                        helperText="Краткое описание для карточки категории"
                                    >
                                        <MarkdownEditor
                                            value={data.short_description}
                                            onChange={(val) => setData('short_description', val)}
                                            placeholder="Введите краткое описание категории"
                                            minHeight="100px"
                                            context={`Категория: ${data.name} (Кратко)`}
                                        />
                                    </FormField>

                                    <FormField
                                        label="Полное описание"
                                        error={errors.description}
                                        helperText="Подробное описание категории"
                                    >
                                        <MarkdownEditor
                                            value={data.description}
                                            onChange={(val) => setData('description', val)}
                                            placeholder="Введите полное описание категории"
                                            minHeight="250px"
                                            context={`Категория: ${data.name}`}
                                        />
                                    </FormField>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 3: SEO */}
                            <Tabs.Content value="seo">
                                <Stack gap={6} mt={6}>
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField
                                            label="Meta Title (SEO заголовок)"
                                            error={errors.meta_title}
                                            helperText="Заголовок для поисковых систем (рекомендуется до 60 символов)"
                                        >
                                            <Input
                                                value={data.meta_title}
                                                onChange={(e) => setData('meta_title', e.target.value)}
                                                placeholder="SEO заголовок категории"
                                            />
                                        </FormField>

                                        <FormField
                                            label="Meta Description (SEO описание)"
                                            error={errors.meta_description}
                                            helperText="Описание для поисковых систем (рекомендуется 150-160 символов)"
                                        >
                                            <Input
                                                value={data.meta_description}
                                                onChange={(e) => setData('meta_description', e.target.value)}
                                                placeholder="SEO описание категории"
                                            />
                                        </FormField>
                                    </SimpleGrid>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 4: Медиа и теги */}
                            <Tabs.Content value="media">
                                <Stack gap={6} mt={6}>
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={6}>
                                        <Box>
                                            <Box fontSize="lg" fontWeight="semibold" mb={4}>
                                                <LuImage style={{ display: 'inline', marginRight: '8px' }} />
                                                Иконка категории
                                            </Box>
                                            <ImageUploader
                                                onChange={(file) => setData('icon', file)}
                                                maxPreviewWidth="150px"
                                                aspectRatio="1"
                                                placeholder="Загрузить иконку категории"
                                                error={errors.icon}
                                            />
                                        </Box>

                                        <Box>
                                            <Box fontSize="lg" fontWeight="semibold" mb={4}>
                                                <LuTag style={{ display: 'inline', marginRight: '8px' }} />
                                                Теги категории
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
                            submitLabel="Создать категорию"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
