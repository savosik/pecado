import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, FileUploader, EntitySelector } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid, NativeSelectRoot, NativeSelectField, Image, Box } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

export default function Edit({ banner }) {
    const [data, setFormData] = useState({
        title: banner.title || '',
        linkable_type: banner.linkable_type || '',
        linkable_id: banner.linkable_id || null,
        _linkable_name: banner.linkable_name || '',
        is_active: banner.is_active ?? true,
        sort_order: banner.sort_order || 0,
        desktop_image: null,
        mobile_image: null,
    });

    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    const setData = (key, value) => {
        if (typeof key === 'object') {
            setFormData(prev => ({ ...prev, ...key }));
        } else {
            setFormData(prev => ({ ...prev, [key]: value }));
        }
    };

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
            linkable_type: e.target.value,
            linkable_id: null,
            _linkable_name: '',
        });
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        const formDataToSend = new FormData();
        formDataToSend.append('_method', 'PUT');

        // Append simple fields
        formDataToSend.append('title', data.title);
        if (data.linkable_type) {
            formDataToSend.append('linkable_type', data.linkable_type);
        }
        if (data.linkable_id) {
            formDataToSend.append('linkable_id', data.linkable_id);
        }
        formDataToSend.append('is_active', data.is_active ? '1' : '0');
        formDataToSend.append('sort_order', data.sort_order);

        // Append files — FileUploader returns array of File objects
        if (Array.isArray(data.desktop_image) && data.desktop_image.length > 0) {
            formDataToSend.append('desktop_image', data.desktop_image[0]);
        } else if (data.desktop_image instanceof File) {
            formDataToSend.append('desktop_image', data.desktop_image);
        }

        if (Array.isArray(data.mobile_image) && data.mobile_image.length > 0) {
            formDataToSend.append('mobile_image', data.mobile_image[0]);
        } else if (data.mobile_image instanceof File) {
            formDataToSend.append('mobile_image', data.mobile_image);
        }

        try {
            await axios.post(route('admin.banners.update', banner.id), formDataToSend, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            toaster.create({
                title: 'Баннер успешно обновлен',
                type: 'success',
            });

            router.visit(route('admin.banners.index'));
        } catch (error) {
            if (error.response?.data?.errors) {
                setErrors(error.response.data.errors);
            }

            toaster.create({
                title: 'Ошибка при обновлении баннера',
                description: 'Проверьте правильность заполнения полей',
                type: 'error',
            });
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            <PageHeader title={`Редактировать баннер: ${banner.title}`} />

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
                                            value={data.linkable_id ? { id: data.linkable_id, name: data._linkable_name || banner.linkable_name } : null}
                                            onChange={(item) => setData({
                                                linkable_id: item ? item.id : null,
                                                _linkable_name: item ? (item.name || item.label) : '',
                                            })}
                                            searchUrl={getEntitySearchUrl(data.linkable_type)}
                                            placeholder={`Выберите ${entityTypeOptions.find(o => o.value === data.linkable_type)?.label.toLowerCase()}`}
                                            initialName={banner.linkable_name}
                                        />
                                    </FormField>
                                )}
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Desktop изображение" error={errors.desktop_image}>
                                    {banner.desktop_image && !data.desktop_image && (
                                        <Box mb={4}>
                                            <Image
                                                src={banner.desktop_image}
                                                alt={banner.title}
                                                maxH="200px"
                                                objectFit="contain"
                                                borderRadius="md"
                                            />
                                        </Box>
                                    )}
                                    <FileUploader
                                        value={data.desktop_image}
                                        onChange={(file) => setData('desktop_image', file)}
                                        accept="image/*,video/*"
                                    />
                                </FormField>

                                <FormField label="Mobile изображение" error={errors.mobile_image}>
                                    {banner.mobile_image && !data.mobile_image && (
                                        <Box mb={4}>
                                            <Image
                                                src={banner.mobile_image}
                                                alt={banner.title}
                                                maxH="200px"
                                                objectFit="contain"
                                                borderRadius="md"
                                            />
                                        </Box>
                                    )}
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
                                submitLabel="Сохранить изменения"
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

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
