import { useMemo , useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { useSlugField } from '@/Admin/hooks/useSlugField';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, MultipleImageUploader, VideoUploader, SelectRelation, MarkdownEditor, TagSelector, BarcodeSelector, CertificateSelector, CategoryTreeSelector, EntitySelector } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack, Tabs } from '@chakra-ui/react';

import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { LuFileText, LuTag, LuDollarSign, LuAlignLeft, LuImage, LuListChecks, LuFolderTree } from 'react-icons/lu';
import { CategoryAttributesSection } from './Components/CategoryAttributesSection';

export default function Create({ brands, categoryTree, sizeCharts }) {
    const { data, setData, post, processing, errors , transform } = useForm({
        name: '',
        slug: '',
        base_price: '',
        brand_id: null,
        model_id: null,
        size_chart_id: null,
        description: '',
        description_html: '',
        short_description: '',
        meta_title: '',
        meta_description: '',
        sku: '',
        code: '',
        external_id: '',
        url: '',
        barcodes: [],
        tnved: '',
        is_new: false,
        is_bestseller: false,
        is_marked: false,
        is_liquidation: false,
        for_marketplaces: false,
        categories: [],

        image: null,
        additional_images: [],
        video: null,
        tags: [],
        certificates: [],
        attributes: [],
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const { handleSourceChange, handleSlugChange } = useSlugField({
        data, setData, sourceField: 'name',
    });

    // Определяем, в каких табах есть ошибки (мемоизируем, чтобы не пересчитывать при каждом вводе)
    const tabErrors = useMemo(() => ({
        general: ['name', 'slug', 'sku', 'code', 'external_id', 'url', 'barcodes', 'tnved'].some(field => errors[field]),
        categories: ['categories'].some(field => errors[field]),
        relations: ['brand_id', 'model_id', 'size_chart_id'].some(field => errors[field]),
        pricing: ['base_price', 'is_new', 'is_bestseller', 'is_marked', 'is_liquidation', 'for_marketplaces'].some(field => errors[field]),
        descriptions: ['short_description', 'description', 'description_html', 'meta_title', 'meta_description'].some(field => errors[field]),
        media: ['image', 'additional_images', 'video'].some(field => errors[field]),
    }), [errors]);

    // Мемоизируем опции для селектов, чтобы избежать лишних ре-рендеров
    const brandOptions = useMemo(() => brands.map(b => ({ value: b.id, label: b.name })), [brands]);
    const sizeChartOptions = useMemo(() => sizeCharts.map(s => ({ value: s.id, label: s.name })), [sizeCharts]);


    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.products.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Товар успешно создан',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при создании товара',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    const handleDescriptionChange = (html) => {
        setData('description', html);
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader
                title="Создать товар"
                description="Заполните информацию о новом товаре"
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
                                <Tabs.Trigger value="relations">
                                    <LuTag /> Связи
                                    {tabErrors.relations && (
                                        <Box as="span" color="red.500" ml={2} fontWeight="bold">
                                            ⚠️
                                        </Box>
                                    )}
                                </Tabs.Trigger>
                                <Tabs.Trigger value="categories">
                                    <LuFolderTree /> Категории
                                    {tabErrors.categories && (
                                        <Box as="span" color="red.500" ml={2} fontWeight="bold">
                                            ⚠️
                                        </Box>
                                    )}
                                </Tabs.Trigger>
                                <Tabs.Trigger value="pricing">
                                    <LuDollarSign /> Цена и статусы
                                    {tabErrors.pricing && (
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
                                <Tabs.Trigger value="media">
                                    <LuImage /> Медиа
                                    {tabErrors.media && (
                                        <Box as="span" color="red.500" ml={2}>
                                            <LuAlertCircle size={16} />
                                        </Box>
                                    )}
                                </Tabs.Trigger>
                                <Tabs.Trigger value="attributes">
                                    <LuListChecks /> Атрибуты
                                </Tabs.Trigger>
                            </Tabs.List>

                            {/* Таб 1: Основная информация */}
                            <Tabs.Content value="general">
                                <Stack gap={6} mt={6}>
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField
                                            label="Название товара"
                                            required
                                            error={errors.name}
                                        >
                                            <Input
                                                value={data.name}
                                                onChange={(e) => handleSourceChange(e.target.value)}
                                                placeholder="Введите название товара"
                                            />
                                        </FormField>

                                        <FormField
                                            label="Slug (ЧПУ)"
                                            error={errors.slug}
                                            helperText="Оставьте пустым для автогенерации"
                                        >
                                            <Input
                                                value={data.slug}
                                                onChange={(e) => handleSlugChange(e.target.value)}
                                                placeholder="Автоматически из названия"
                                            />
                                        </FormField>

                                        <FormField
                                            label="Артикул (SKU)"
                                            error={errors.sku}
                                        >
                                            <Input
                                                value={data.sku}
                                                onChange={(e) => setData('sku', e.target.value)}
                                                placeholder="Введите артикул"
                                            />
                                        </FormField>

                                        <FormField
                                            label="Код товара"
                                            error={errors.code}
                                        >
                                            <Input
                                                value={data.code}
                                                onChange={(e) => setData('code', e.target.value)}
                                                placeholder="Введите код товара"
                                            />
                                        </FormField>

                                        <FormField
                                            label="Внешний ID"
                                            error={errors.external_id}
                                            helperText="ID из внешней системы/интеграции"
                                        >
                                            <Input
                                                value={data.external_id}
                                                onChange={(e) => setData('external_id', e.target.value)}
                                                placeholder="Внешний ID"
                                            />
                                        </FormField>

                                        <FormField
                                            label="Штрихкоды"
                                            error={errors.barcodes}
                                            helperText="Введите штрихкод и нажмите Enter. Первый штрихкод будет основным."
                                        >
                                            <BarcodeSelector
                                                value={data.barcodes}
                                                onChange={(barcodes) => setData('barcodes', barcodes)}
                                                placeholder="Введите штрихкод..."
                                            />
                                        </FormField>

                                        <FormField
                                            label="Код ТН ВЭД"
                                            error={errors.tnved}
                                            helperText="Код товарной номенклатуры внешнеэкономической деятельности"
                                        >
                                            <Input
                                                value={data.tnved}
                                                onChange={(e) => setData('tnved', e.target.value)}
                                                placeholder="Код ТН ВЭД"
                                            />
                                        </FormField>

                                        <FormField
                                            label="URL товара"
                                            error={errors.url}
                                        >
                                            <Input
                                                value={data.url}
                                                onChange={(e) => setData('url', e.target.value)}
                                                placeholder="https://example.com/product"
                                            />
                                        </FormField>
                                    </SimpleGrid>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб: Категории */}
                            <Tabs.Content value="categories">
                                <Stack gap={6} mt={6}>
                                    <CategoryTreeSelector
                                        categoryTree={categoryTree}
                                        value={data.categories}
                                        onChange={(ids) => setData('categories', ids)}
                                    />
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 2: Связи и классификация */}
                            <Tabs.Content value="relations">
                                <Stack gap={6} mt={6}>
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <SelectRelation
                                            label="Бренд"
                                            value={data.brand_id}
                                            onChange={(value) => setData('brand_id', value)}
                                            options={brandOptions}
                                            placeholder="Выберите бренд"
                                            error={errors.brand_id}
                                        />

                                        <FormField label="Модель" error={errors.model_id}>
                                            <EntitySelector
                                                value={data.model_id}
                                                onChange={(item) => setData('model_id', item ? item.id : null)}
                                                searchUrl="admin.product-models.search"
                                                displayField="name"
                                                placeholder="Поиск модели..."
                                                error={errors.model_id}
                                            />
                                        </FormField>

                                        <SelectRelation
                                            label="Размерная сетка"
                                            value={data.size_chart_id}
                                            onChange={(value) => setData('size_chart_id', value)}
                                            options={sizeChartOptions}
                                            placeholder="Выберите размерную сетку"
                                            error={errors.size_chart_id}
                                        />

                                        <Box gridColumn={{ base: '1', md: 'span 2' }}>
                                            <FormField
                                                label="Теги"
                                                error={errors.tags}
                                            >
                                                <TagSelector
                                                    value={data.tags}
                                                    onChange={(tags) => setData('tags', tags)}
                                                    placeholder="Введите теги..."
                                                    error={errors.tags}
                                                />
                                            </FormField>
                                        </Box>

                                        <Box gridColumn={{ base: '1', md: 'span 2' }}>
                                            <FormField
                                                label="Сертификаты"
                                                error={errors.certificates}
                                            >
                                                <CertificateSelector
                                                    value={data.certificates}
                                                    onChange={(certificates) => setData('certificates', certificates)}
                                                    error={errors.certificates}
                                                />
                                            </FormField>
                                        </Box>
                                    </SimpleGrid>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 3: Цена и статусы */}
                            <Tabs.Content value="pricing">
                                <Stack gap={6} mt={6}>
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField
                                            label="Базовая цена"
                                            required
                                            error={errors.base_price}
                                        >
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={data.base_price}
                                                onChange={(e) => setData('base_price', e.target.value)}
                                                placeholder="0.00"
                                            />
                                        </FormField>

                                        <Box /> {/* Пустая ячейка для выравнивания */}

                                        <Field label="Новинка">
                                            <Switch
                                                checked={data.is_new}
                                                onCheckedChange={(e) => setData('is_new', e.checked)}
                                                colorPalette="blue"
                                            >
                                                {data.is_new ? 'Да' : 'Нет'}
                                            </Switch>
                                        </Field>

                                        <Field label="Бестселлер">
                                            <Switch
                                                checked={data.is_bestseller}
                                                onCheckedChange={(e) => setData('is_bestseller', e.checked)}
                                                colorPalette="blue"
                                            >
                                                {data.is_bestseller ? 'Да' : 'Нет'}
                                            </Switch>
                                        </Field>

                                        <Field label="Маркировка (честный знак)">
                                            <Switch
                                                checked={data.is_marked}
                                                onCheckedChange={(e) => setData('is_marked', e.checked)}
                                                colorPalette="blue"
                                            >
                                                {data.is_marked ? 'Да' : 'Нет'}
                                            </Switch>
                                        </Field>

                                        <Field label="Ликвидация">
                                            <Switch
                                                checked={data.is_liquidation}
                                                onCheckedChange={(e) => setData('is_liquidation', e.checked)}
                                                colorPalette="orange"
                                            >
                                                {data.is_liquidation ? 'Да' : 'Нет'}
                                            </Switch>
                                        </Field>

                                        <Field label="Для маркетплейсов">
                                            <Switch
                                                checked={data.for_marketplaces}
                                                onCheckedChange={(e) => setData('for_marketplaces', e.checked)}
                                                colorPalette="green"
                                            >
                                                {data.for_marketplaces ? 'Да' : 'Нет'}
                                            </Switch>
                                        </Field>
                                    </SimpleGrid>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 4: Описания */}
                            <Tabs.Content value="descriptions">
                                <Stack gap={6} mt={6}>
                                    <FormField
                                        label="Краткое описание"
                                        error={errors.short_description}
                                        helperText="Краткое описание для карточки товара"
                                    >
                                        <MarkdownEditor
                                            value={data.short_description}
                                            onChange={(val) => setData('short_description', val)}
                                            placeholder="Введите краткое описание товара"
                                            minHeight="100px"
                                            context={`Товар: ${data.name} (Кратко)`}
                                        />
                                    </FormField>

                                    <FormField
                                        label="Полное описание"
                                        error={errors.description}
                                        helperText="Подробное описание товара (Markdown)"
                                    >
                                        <MarkdownEditor
                                            value={data.description}
                                            onChange={handleDescriptionChange}
                                            placeholder="Введите полное описание товара"
                                            minHeight="300px"
                                            context={`Товар: ${data.name}`}
                                        />
                                    </FormField>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField
                                            label="Meta Title (SEO заголовок)"
                                            error={errors.meta_title}
                                            helperText="Заголовок для поисковых систем (рекомендуется до 60 символов)"
                                        >
                                            <Input
                                                value={data.meta_title}
                                                onChange={(e) => setData('meta_title', e.target.value)}
                                                placeholder="SEO заголовок товара"
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
                                                placeholder="SEO описание товара"
                                            />
                                        </FormField>
                                    </SimpleGrid>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 5: Медиа */}
                            <Tabs.Content value="media">
                                <Stack gap={6} mt={6}>
                                    <Box>
                                        <Box fontSize="lg" fontWeight="semibold" mb={4}>
                                            Главное изображение
                                        </Box>

                                        <ImageUploader
                                            onChange={(file) => setData('image', file)}
                                            error={errors.image}
                                            maxPreviewWidth="300px"
                                            aspectRatio="2/3"
                                            placeholder="Загрузить главное изображение"
                                        />
                                    </Box>

                                    <Box>
                                        <Box fontSize="lg" fontWeight="semibold" mb={4}>
                                            Дополнительные медиа
                                        </Box>

                                        <Stack gap={4}>
                                            <MultipleImageUploader
                                                value={data.additional_images}
                                                onChange={(files) => setData('additional_images', files)}
                                                error={errors.additional_images}
                                                label="Дополнительные изображения товара"
                                                maxFiles={10}
                                            />

                                            <VideoUploader
                                                value={data.video}
                                                onChange={(file) => setData('video', file)}
                                                error={errors.video}
                                                label="Видео товара"
                                            />
                                        </Stack>
                                    </Box>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 6: Атрибуты */}
                            <Tabs.Content value="attributes">
                                <Stack gap={6} mt={6}>
                                    <CategoryAttributesSection
                                        categoryIds={data.categories}
                                        value={data.attributes}
                                        onChange={(attrs) => setData('attributes', attrs)}
                                        errors={errors.attributes}
                                    />
                                </Stack>
                            </Tabs.Content>
                        </Tabs.Root>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            loading={processing}
                            onCancel={() => window.history.back()}
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
