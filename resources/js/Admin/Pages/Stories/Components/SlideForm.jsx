import { useState } from 'react';
import { Stack, Input, Textarea, Button, HStack, Box, NativeSelectRoot, NativeSelectField, Image } from '@chakra-ui/react';
import { FormField, FileUploader, EntitySelector } from '@/Admin/Components';
import axios from 'axios';
import { toaster } from '@/components/ui/toaster';
import { LuSave, LuX } from 'react-icons/lu';

export default function SlideForm({ slide, story, onSave, onCancel }) {
    const [formData, setFormData] = useState({
        title: slide.title || '',
        content: slide.content || '',
        button_text: slide.button_text || '',
        button_url: slide.button_url || '',
        linkable_type: slide.linkable_type || '',
        linkable_id: slide.linkable_id || null,
        duration: slide.duration || 5,
        sort_order: slide.sort_order || 0,
        media: null,
    });

    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [currentMedia, setCurrentMedia] = useState(slide.media_url);

    const entityTypeOptions = [
        { value: '', label: 'Без ссылки' },
        { value: 'App\\Models\\Product', label: 'Товар' },
        { value: 'App\\Models\\Page', label: 'Страница' },
        { value: 'App\\Models\\Article', label: 'Статья' },
        { value: 'App\\Models\\Category', label: 'Категория' },
        { value: 'App\\Models\\News', label: 'Новость' },
        { value: 'App\\Models\\Promotion', label: 'Акция' },
    ];

    const getEntitySearchUrl = (type) => {
        const urlMap = {
            'App\\Models\\Product': route('admin.products.search'),
            'App\\Models\\Page': route('admin.pages.search'),
            'App\\Models\\Article': route('admin.articles.search'),
            'App\\Models\\Category': route('admin.categories.search'),
            'App\\Models\\News': route('admin.news.search'),
            'App\\Models\\Promotion': route('admin.promotions.search'),
        };
        return urlMap[type] || '';
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        const formDataToSend = new FormData();

        Object.keys(formData).forEach(key => {
            if (formData[key] !== null && formData[key] !== '') {
                if (key === 'media' && formData[key] instanceof File) {
                    formDataToSend.append(key, formData[key]);
                } else if (key !== 'media') {
                    formDataToSend.append(key, formData[key]);
                }
            }
        });

        try {
            let response;

            if (slide.isNew) {
                // Создание нового слайда
                response = await axios.post(
                    route('admin.stories.slides.store', story.id),
                    formDataToSend,
                    {
                        headers: { 'Content-Type': 'multipart/form-data' },
                    }
                );

                toaster.create({
                    title: 'Слайд успешно создан',
                    type: 'success',
                });
            } else {
                // Обновление существующего слайда
                formDataToSend.append('_method', 'PUT');

                response = await axios.post(
                    route('admin.stories.slides.update', { story: story.id, slide: slide.id }),
                    formDataToSend,
                    {
                        headers: { 'Content-Type': 'multipart/form-data' },
                    }
                );

                toaster.create({
                    title: 'Слайд успешно обновлён',
                    type: 'success',
                });
            }

            // Обновить слайд в списке
            if (response.data.slide) {
                onSave(response.data.slide);
            }
        } catch (error) {
            if (error.response?.data?.errors) {
                setErrors(error.response.data.errors);
            }

            toaster.create({
                title: slide.isNew ? 'Ошибка при создании слайда' : 'Ошибка при обновлении слайда',
                description: error.response?.data?.message || 'Попробуйте снова',
                type: 'error',
            });
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <Stack gap={4}>
                <FormField label="Заголовок" error={errors.title}>
                    <Input
                        value={formData.title}
                        onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                        placeholder="Заголовок слайда"
                    />
                </FormField>

                <FormField label="Контент" error={errors.content}>
                    <Textarea
                        value={formData.content}
                        onChange={(e) => setFormData({ ...formData, content: e.target.value })}
                        placeholder="Текстовый контент слайда"
                        rows={3}
                    />
                </FormField>

                <FormField label="Медиафайл" required={slide.isNew} error={errors.media}>
                    {currentMedia && !formData.media && (
                        <Box mb={2}>
                            <Image
                                src={currentMedia}
                                alt="Текущее медиа"
                                maxH="150px"
                                objectFit="contain"
                                borderRadius="md"
                            />
                        </Box>
                    )}
                    <FileUploader
                        value={formData.media}
                        onChange={(file) => setFormData({ ...formData, media: file })}
                        accept="image/*,video/*"
                    />
                </FormField>

                <FormField label="Длительность (секунды)" required error={errors.duration}>
                    <Input
                        type="number"
                        min="1"
                        max="60"
                        value={formData.duration}
                        onChange={(e) => setFormData({ ...formData, duration: parseInt(e.target.value) || 5 })}
                    />
                </FormField>

                <FormField label="Текст кнопки" error={errors.button_text}>
                    <Input
                        value={formData.button_text}
                        onChange={(e) => setFormData({ ...formData, button_text: e.target.value })}
                        placeholder="Например: Подробнее"
                    />
                </FormField>

                <FormField label="Тип ссылки" error={errors.linkable_type}>
                    <NativeSelectRoot>
                        <NativeSelectField
                            value={formData.linkable_type}
                            onChange={(e) => {
                                setFormData({
                                    ...formData,
                                    linkable_type: e.target.value,
                                    linkable_id: null,
                                });
                            }}
                        >
                            {entityTypeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelectField>
                    </NativeSelectRoot>
                </FormField>

                {formData.linkable_type && (
                    <FormField label="Сущность" error={errors.linkable_id}>
                        <EntitySelector
                            value={formData.linkable_id}
                            onChange={(value) => setFormData({ ...formData, linkable_id: value })}
                            searchUrl={getEntitySearchUrl(formData.linkable_type)}
                            placeholder={`Выберите ${entityTypeOptions.find(o => o.value === formData.linkable_type)?.label.toLowerCase()}`}
                            initialName={slide.linkable_name}
                        />
                    </FormField>
                )}

                {!formData.linkable_type && (
                    <FormField label="URL кнопки" error={errors.button_url}>
                        <Input
                            value={formData.button_url}
                            onChange={(e) => setFormData({ ...formData, button_url: e.target.value })}
                            placeholder="https://example.com"
                        />
                    </FormField>
                )}

                <HStack gap={3}>
                    <Button
                        type="submit"
                        colorPalette="blue"
                        loading={saving}
                    >
                        <LuSave />
                        {slide.isNew ? 'Создать слайд' : 'Сохранить'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onCancel}
                    >
                        <LuX />
                        Отмена
                    </Button>
                </HStack>
            </Stack>
        </form>
    );
}
