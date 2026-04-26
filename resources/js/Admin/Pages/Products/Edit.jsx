import { useMemo, useRef } from 'react';
import { useForm, router } from '@inertiajs/react';
import { useSlugField } from '@/Admin/hooks/useSlugField';
import axios from 'axios';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, MultipleImageUploader, VideoUploader, SelectRelation, EditorJsEditor, MarkdownTextEditor, TagSelector, BarcodeSelector, CertificateSelector, CategoryTreeSelector, EntitySelector, SimpleWysiwyg } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Textarea, Stack, Tabs } from '@chakra-ui/react';

import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { LuFileText, LuTag, LuDollarSign, LuAlignLeft, LuImage, LuWarehouse, LuListChecks, LuFolderTree, LuPackage } from 'react-icons/lu';
import { WarehousesSection } from './Components/WarehousesSection';
import { CategoryAttributesSection } from './Components/CategoryAttributesSection';

export default function Edit({ product, brands, categoryTree, modelName, sizeCharts, warehouses, attributes, certificates, productSelections }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: product.name || '',
        slug: product.slug || '',
        base_price: product.base_price || '',
        brand_id: product.brand_id || null,
        model_id: product.model_id || null,
        size_chart_id: product.size_chart_id || null,
        description: product.description || '',
        description_html: product.description_html || '',
        short_description: product.short_description || '',
        rich_content: product.rich_content ? JSON.stringify(product.rich_content) : '',
        meta_title: product.meta_title || '',
        meta_description: product.meta_description || '',
        sku: product.sku || '',
        variant_name: product.variant_name || '',
        code: product.code || '',
        external_id: product.external_id || '',
        sex_opt_id: product.sex_opt_id || '',
        url: product.url || '',
        barcodes: product.barcodes || [],
        tnved: product.tnved || '',
        weight_gross: product.weight_gross ?? '',
        weight_net: product.weight_net ?? '',
        width: product.width ?? '',
        height: product.height ?? '',
        depth: product.depth ?? '',
        hs_code: product.hs_code ?? '',
        abc_xyz: product.abc_xyz ?? '',
        turnover: product.turnover ?? '',
        is_new: product.is_new || false,
        is_bestseller: product.is_bestseller || false,
        is_marked: product.is_marked || false,
        is_liquidation: product.is_liquidation || false,
        for_marketplaces: product.for_marketplaces || false,
        hidden: product.hidden || false,
        category_id: product.category_id || null,

        additional_images: [],
        video: null,
        tags: product.tags ? product.tags.map(t => t.value) : [],
        certificates: product.certificates || [],
        warehouses: product.warehouses || [],
        attributes: product.attributes || [],
        product_selections: product.product_selections || [],
        _method: 'PUT',
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const { handleSourceChange, handleSlugChange } = useSlugField({
        data, setData, sourceField: 'name', isEditing: true,
    });

    // Определяем, в каких табах есть ошибки
    // Определяем, в каких табах есть ошибки (мемоизируем)
    const tabErrors = useMemo(() => ({
        general: ['name', 'slug', 'sku', 'variant_name', 'code', 'external_id', 'sex_opt_id', 'url', 'barcodes', 'tnved'].some(field => errors[field]),
        categories: ['category_id'].some(field => errors[field]),
        relations: ['brand_id', 'model_id', 'size_chart_id'].some(field => errors[field]),
        pricing: ['base_price', 'is_new', 'is_bestseller', 'is_marked', 'is_liquidation', 'for_marketplaces', 'hidden'].some(field => errors[field]),
        descriptions: ['short_description', 'description', 'description_html', 'meta_title', 'meta_description'].some(field => errors[field]),
        richContent: ['rich_content'].some(field => errors[field]),
        media: ['image', 'additional_images', 'video'].some(field => errors[field]),
        logistics: ['weight_gross', 'weight_net', 'width', 'height', 'depth', 'hs_code', 'abc_xyz', 'turnover'].some(field => errors[field]),
    }), [errors]);

    // Мемоизируем опции для селектов
    const brandOptions = useMemo(() => brands.map(b => ({ value: b.id, label: b.name })), [brands]);
    const sizeChartOptions = useMemo(() => sizeCharts.map(s => ({ value: s.id, label: s.name })), [sizeCharts]);
    const selectionOptions = useMemo(() => (productSelections || []).map(s => ({ value: s.id, label: s.name })), [productSelections]);


    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.products.update', product.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Товар успешно обновлён',
                    type: 'success',
                });
            },
            onError: (formErrors) => {
                const errorMessages = Object.values(formErrors).flat();
                toaster.create({
                    title: 'Ошибка при обновлении товара',
                    description: errorMessages.length > 0
                        ? errorMessages.join('; ')
                        : 'Проверьте правильность заполнения полей',
                    type: 'error',
                    duration: 10000,
                });
            },
        });
    };

    const handleRichContentChange = (jsonString) => {
        setData('rich_content', jsonString);
    };

    const handleDeleteMainImage = async () => {
        if (!product.main_image_id) return;

        try {
            await axios.delete(route('admin.products.media.delete', product.id), {
                data: { media_id: product.main_image_id },
            });

            toaster.create({
                title: 'Главное изображение удалено',
                type: 'success',
            });

            // Перезагрузить страницу для обновления данных
            router.reload({ preserveScroll: true });
        } catch (error) {
            toaster.create({
                title: 'Ошибка при удалении изображения',
                type: 'error',
            });
        }
    };

    const handleDeleteAdditionalImage = async (mediaId) => {
        try {
            await axios.delete(route('admin.products.media.delete', product.id), {
                data: { media_id: mediaId },
            });

            toaster.create({
                title: 'Изображение удалено',
                type: 'success',
            });

            // Перезагрузить страницу для обновления данных
            router.reload({ preserveScroll: true });
        } catch (error) {
            toaster.create({
                title: 'Ошибка при удалении изображения',
                type: 'error',
            });
        }
    };

    const handleDeleteVideo = async () => {
        if (!product.video_id) return;

        try {
            await axios.delete(route('admin.products.media.delete', product.id), {
                data: { media_id: product.video_id },
            });

            toaster.create({
                title: 'Видео удалено',
                type: 'success',
            });

            // Перезагрузить страницу для обновления данных
            router.reload({ preserveScroll: true });
        } catch (error) {
            toaster.create({
                title: 'Ошибка при удалении видео',
                type: 'error',
            });
        }
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader
                title="Редактировать товар"
                description={`Редактирование: ${product.name}`}
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
                                <Tabs.Trigger value="inventory">
                                    <LuWarehouse /> Склады
                                </Tabs.Trigger>
                                <Tabs.Trigger value="logistics">
                                    <LuPackage /> Габариты и логистика
                                    {tabErrors.logistics && (
                                        <Box as="span" color="red.500" ml={2} fontWeight="bold">
                                            ⚠️
                                        </Box>
                                    )}
                                </Tabs.Trigger>
                                <Tabs.Trigger value="attributes">
                                    <LuListChecks /> Атрибуты
                                </Tabs.Trigger>
                                <Tabs.Trigger value="rich_content">
                                    <LuAlignLeft /> Rich-контент
                                    {tabErrors.richContent && (
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
                                            label="Название варианта"
                                            error={errors.variant_name}
                                            helperText="Если заполнено — отображается как подпись варианта на странице товара вместо автогенерируемой"
                                        >
                                            <Input
                                                value={data.variant_name}
                                                onChange={(e) => setData('variant_name', e.target.value)}
                                                placeholder="Напр.: Красный XL"
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
                                            label="Ид sex-opt"
                                            error={errors.sex_opt_id}
                                        >
                                            <Input
                                                value={data.sex_opt_id}
                                                onChange={(e) => setData('sex_opt_id', e.target.value)}
                                                placeholder="Ид sex-opt"
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

                                    <Box>
                                        <Box fontSize="md" fontWeight="semibold" mb={3}>Аудит-метки 1С</Box>
                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                            <FormField
                                                label="Создано в 1С"
                                                helperText="Дата создания номенклатуры в 1С (только для чтения, приходит по шине ERP)"
                                            >
                                                <Input value={product.erp_created_at || '—'} readOnly disabled />
                                            </FormField>
                                            <FormField
                                                label="Изменено в 1С"
                                                helperText="Дата последнего изменения номенклатуры в 1С (только для чтения, приходит по шине ERP)"
                                            >
                                                <Input value={product.erp_updated_at || '—'} readOnly disabled />
                                            </FormField>
                                        </SimpleGrid>
                                    </Box>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб: Категории */}
                            <Tabs.Content value="categories">
                                <Stack gap={6} mt={6}>
                                    <CategoryTreeSelector
                                        categoryTree={categoryTree}
                                        value={data.category_id}
                                        onChange={(id) => setData('category_id', id)}
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
                                                initialDisplay={modelName}
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

                                        <Box gridColumn={{ base: '1', md: 'span 2' }}>
                                            <SelectRelation
                                                label="Подборки"
                                                value={data.product_selections}
                                                onChange={(value) => setData('product_selections', value)}
                                                options={selectionOptions}
                                                multiple={true}
                                                placeholder="Выберите подборки..."
                                                error={errors.product_selections}
                                            />
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

                                        <FormField label="Новинка">
                                            <Switch
                                                checked={data.is_new}
                                                onCheckedChange={(e) => setData('is_new', e.checked)}
                                                colorPalette="blue"
                                            >
                                                {data.is_new ? 'Да' : 'Нет'}
                                            </Switch>
                                        </FormField>

                                        <FormField label="Бестселлер">
                                            <Switch
                                                checked={data.is_bestseller}
                                                onCheckedChange={(e) => setData('is_bestseller', e.checked)}
                                                colorPalette="blue"
                                            >
                                                {data.is_bestseller ? 'Да' : 'Нет'}
                                            </Switch>
                                        </FormField>

                                        <FormField label="Маркировка (честный знак)">
                                            <Switch
                                                checked={data.is_marked}
                                                onCheckedChange={(e) => setData('is_marked', e.checked)}
                                                colorPalette="blue"
                                            >
                                                {data.is_marked ? 'Да' : 'Нет'}
                                            </Switch>
                                        </FormField>

                                        <FormField label="Ликвидация">
                                            <Switch
                                                checked={data.is_liquidation}
                                                onCheckedChange={(e) => setData('is_liquidation', e.checked)}
                                                colorPalette="orange"
                                            >
                                                {data.is_liquidation ? 'Да' : 'Нет'}
                                            </Switch>
                                        </FormField>

                                        <FormField label="Для маркетплейсов">
                                            <Switch
                                                checked={data.for_marketplaces}
                                                onCheckedChange={(e) => setData('for_marketplaces', e.checked)}
                                                colorPalette="green"
                                            >
                                                {data.for_marketplaces ? 'Да' : 'Нет'}
                                            </Switch>
                                        </FormField>

                                        <FormField label="Скрыть в интернете (v10)">
                                            <Switch
                                                checked={data.hidden}
                                                onCheckedChange={(e) => setData('hidden', e.checked)}
                                                colorPalette="red"
                                            >
                                                {data.hidden ? 'Да' : 'Нет'}
                                            </Switch>
                                        </FormField>
                                    </SimpleGrid>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 4: Описания */}
                            <Tabs.Content value="descriptions">
                                <Stack gap={6} mt={6}>
                                    <FormField
                                        label="Краткое описание"
                                        error={errors.short_description}
                                        helperText="Краткое описание для карточки товара (текст/HTML для выгрузок)"
                                    >
                                        <Textarea
                                            value={data.short_description}
                                            onChange={(e) => setData('short_description', e.target.value)}
                                            placeholder="Введите краткое описание товара"
                                            rows={3}
                                        />
                                    </FormField>

                                    <FormField
                                        label="Полное описание"
                                        error={errors.description}
                                        helperText="Подробное описание товара в формате Markdown. На сайте отображается как отформатированный HTML."
                                        w="100%"
                                        alignItems="stretch"
                                    >
                                        <MarkdownTextEditor
                                            value={data.description}
                                            onChange={(val) => setData('description', val)}
                                            placeholder="Введите описание товара в формате Markdown..."
                                            minHeight={320}
                                        />
                                    </FormField>

                                    <FormField
                                        label="Описание (HTML)"
                                        error={errors.description_html}
                                        helperText="HTML-версия описания с форматированием (для выгрузок)"
                                        w="100%"
                                        alignItems="stretch"
                                    >
                                        <SimpleWysiwyg
                                            value={data.description_html}
                                            onChange={(html) => setData('description_html', html)}
                                            placeholder="Оформите описание товара..."
                                            minHeight="200px"
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
                                            existingUrl={product.main_image}
                                            onRemoveExisting={product.main_image_id ? handleDeleteMainImage : null}
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
                                                existingImages={product.additional_media}
                                                onChange={(files) => setData('additional_images', files)}
                                                onRemoveExisting={handleDeleteAdditionalImage}
                                                error={errors.additional_images}
                                                label="Дополнительные изображения товара"
                                                maxFiles={10}
                                            />

                                            <VideoUploader
                                                value={data.video}
                                                existingVideo={product.video_url}
                                                onChange={(file) => setData('video', file)}
                                                onRemoveExisting={handleDeleteVideo}
                                                error={errors.video}
                                                label="Видео товара"
                                            />
                                        </Stack>
                                    </Box>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 6: Склады */}
                            <Tabs.Content value="inventory">
                                <Stack gap={6} mt={6}>
                                    <WarehousesSection
                                        warehouses={data.warehouses}
                                        availableWarehouses={warehouses}
                                        onChange={(wh) => setData('warehouses', wh)}
                                        error={errors.warehouses}
                                    />
                                </Stack>
                            </Tabs.Content>

                            {/* Таб: Габариты и логистика */}
                            <Tabs.Content value="logistics">
                                <Stack gap={6} mt={6}>
                                    <Box>
                                        <Box fontSize="md" fontWeight="semibold" mb={3}>Вес</Box>
                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                            <FormField
                                                label="Вес брутто, кг"
                                                error={errors.weight_gross}
                                                helperText="Из «Упаковки.Вес» в 1С (первая непомеченная упаковка)"
                                            >
                                                <Input
                                                    type="number"
                                                    step="0.001"
                                                    min="0"
                                                    value={data.weight_gross}
                                                    onChange={(e) => setData('weight_gross', e.target.value)}
                                                    placeholder="0.000"
                                                />
                                            </FormField>
                                            <FormField
                                                label="Вес нетто, кг"
                                                error={errors.weight_net}
                                                helperText="Из «Номенклатура.ЕдиницаИзмерения.Вес» в 1С"
                                            >
                                                <Input
                                                    type="number"
                                                    step="0.001"
                                                    min="0"
                                                    value={data.weight_net}
                                                    onChange={(e) => setData('weight_net', e.target.value)}
                                                    placeholder="0.000"
                                                />
                                            </FormField>
                                        </SimpleGrid>
                                    </Box>

                                    <Box>
                                        <Box fontSize="md" fontWeight="semibold" mb={3}>Габариты упаковки</Box>
                                        <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                                            <FormField label="Ширина, м" error={errors.width}>
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    value={data.width}
                                                    onChange={(e) => setData('width', e.target.value)}
                                                    placeholder="0.00"
                                                />
                                            </FormField>
                                            <FormField label="Высота, м" error={errors.height}>
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    value={data.height}
                                                    onChange={(e) => setData('height', e.target.value)}
                                                    placeholder="0.00"
                                                />
                                            </FormField>
                                            <FormField label="Глубина, м" error={errors.depth}>
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    value={data.depth}
                                                    onChange={(e) => setData('depth', e.target.value)}
                                                    placeholder="0.00"
                                                />
                                            </FormField>
                                        </SimpleGrid>
                                    </Box>

                                    <Box>
                                        <Box fontSize="md" fontWeight="semibold" mb={3}>Классификация</Box>
                                        <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                                            <FormField
                                                label="Код ТН ВЭД"
                                                error={errors.hs_code}
                                                helperText="Из «Номенклатура.КодТНВЭД.Код», до 20 символов"
                                            >
                                                <Input
                                                    value={data.hs_code}
                                                    onChange={(e) => setData('hs_code', e.target.value)}
                                                    maxLength={20}
                                                    placeholder="6204620000"
                                                />
                                            </FormField>
                                            <FormField
                                                label="ABC/XYZ"
                                                error={errors.abc_xyz}
                                                helperText="Класс ABC/XYZ, например AX"
                                            >
                                                <Input
                                                    value={data.abc_xyz}
                                                    onChange={(e) => setData('abc_xyz', e.target.value.toUpperCase())}
                                                    maxLength={5}
                                                    placeholder="AX"
                                                />
                                            </FormField>
                                            <FormField
                                                label="Оборачиваемость"
                                                error={errors.turnover}
                                                helperText="Коэффициент оборачиваемости товара"
                                            >
                                                <Input
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    value={data.turnover}
                                                    onChange={(e) => setData('turnover', e.target.value)}
                                                    placeholder="0.0000"
                                                />
                                            </FormField>
                                        </SimpleGrid>
                                    </Box>
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 7: Атрибуты */}
                            <Tabs.Content value="attributes">
                                <Stack gap={6} mt={6}>
                                    <CategoryAttributesSection
                                        categoryId={data.category_id}
                                        value={data.attributes}
                                        onChange={(attrs) => setData('attributes', attrs)}
                                        errors={errors.attributes}
                                    />
                                </Stack>
                            </Tabs.Content>

                            {/* Таб: Rich-контент (Editor.js) */}
                            <Tabs.Content value="rich_content">
                                <Stack gap={6} mt={6}>
                                    <FormField
                                        label="Rich-контент для сайта"
                                        error={errors.rich_content}
                                        helperText="Расширенное описание товара с блоками для отображения на сайте. Если заполнено — отображается вместо обычного описания."
                                        w="100%"
                                        alignItems="stretch"
                                    >
                                        <EditorJsEditor
                                            value={data.rich_content}
                                            onChange={handleRichContentChange}
                                            placeholder="Добавьте блоки контента для отображения на сайте..."
                                            minHeight="400px"
                                            context={`Товар: ${data.name}`}
                                        />
                                    </FormField>
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

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
