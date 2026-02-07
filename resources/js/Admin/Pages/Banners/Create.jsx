import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, FileUploader, EntitySelector } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid, NativeSelectRoot, NativeSelectField } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        linkable_type: '',
        linkable_id: null,
        is_active: true,
        sort_order: 0,
        desktop_image: null,
        mobile_image: null,
    });

    const entityTypeOptions = [
        { value: '', label: 'Нет ссылки' },
        { value: 'App\\Models\\Product', label: 'Товар' },
        { value: 'App\\Models\\Page', label: 'Страница' },
        { value: 'App\\Models\\Article', label: 'Статья' },
        { value: 'App\\Models\\Category', label: 'Категория' },
        { value: 'App\\Models\\News', label: 'Новость' },
        { value: 'App\\Models\\Promotion', label: 'Акция' },
    ];

    const getEntitySearchUrl = (type) => {
        const routeMap = {
            'App\\Models\\Product': 'admin.products.search',
            'App\\Models\\Page': 'admin.pages.search',
            'App\\Models\\Article': 'admin.articles.search',
            'App\\Models\\Category': 'admin.categories.search',
            'App\\Models\\News': 'admin.news.search',
            'App\\Models\\Promotion': 'admin.promotions.search',
        };
        const routeName = routeMap[type];
        return routeName ? route(routeName) : '';
    };

    const handleTypeChange = (e) => {
        setData({
            ...data,
            linkable_type: e.target.value,
            linkable_id: null, // Сбросить ID при смене типа
        });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.banners.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Баннер успешно создан',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при создании баннера',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    return (
        <>
            <PageHeader title="Создать баннер" />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <FormField label="Заголовок" error={errors.title} required>
                                <Input
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="Введите заголовок баннера"
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Тип ссылки" error={errors.linkable_type}>
                                    <NativeSelectRoot>
                                        <NativeSelectField
                                            value={data.linkable_type}
                                            onChange={handleTypeChange}
                                        >
                                            {entityTypeOptions.map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </FormField>

                                {data.linkable_type && (
                                    <FormField label="Сущность" error={errors.linkable_id}>
                                        <EntitySelector
                                            value={data.linkable_id}
                                            onChange={(value) => setData('linkable_id', value)}
                                            searchUrl={getEntitySearchUrl(data.linkable_type)}
                                            placeholder={`Выберите ${entityTypeOptions.find(o => o.value === data.linkable_type)?.label.toLowerCase()}`}
                                        />
                                    </FormField>
                                )}
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Desktop изображение" error={errors.desktop_image} required>
                                    <FileUploader
                                        value={data.desktop_image}
                                        onChange={(file) => setData('desktop_image', file)}
                                        accept="image/*,video/*"
                                    />
                                </FormField>

                                <FormField label="Mobile изображение" error={errors.mobile_image} required>
                                    <FileUploader
                                        value={data.mobile_image}
                                        onChange={(file) => setData('mobile_image', file)}
                                        accept="image/*,video/*"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Активность" error={errors.is_active}>
                                    <Switch
                                        checked={data.is_active}
                                        onCheckedChange={(e) => setData('is_active', e.checked)}
                                    >
                                        Баннер активен
                                    </Switch>
                                </FormField>

                                <FormField label="Порядок сортировки" error={errors.sort_order}>
                                    <Input
                                        type="number"
                                        value={data.sort_order}
                                        onChange={(e) => setData('sort_order', parseInt(e.target.value) || 0)}
                                        placeholder="0"
                                        min={0}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormActions
                                submitLabel="Создать баннер"
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
