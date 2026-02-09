import React from 'react';
import { Stack, Text, SimpleGrid, Box } from '@chakra-ui/react';
import { ImageUploader } from './ImageUploader';
import { FormField } from './FormField';

/**
 * ContentMediaFields — группа из 3 полей загрузки изображений,
 * соответствующих коллекциям трейта HasContentMedia:
 * - list-item (изображение для списка)
 * - detail-item-desktop (десктопное изображение детальной страницы)
 * - detail-item-mobile (мобильное изображение детальной страницы)
 *
 * @param {Object} data - Данные формы useForm
 * @param {Function} setData - Функция установки данных useForm
 * @param {Object} errors - Ошибки валидации
 * @param {Object} existing - Существующие изображения { list_image, detail_desktop_image, detail_mobile_image }
 */
export const ContentMediaFields = ({
    data,
    setData,
    errors = {},
    existing = {},
}) => {
    return (
        <Stack gap={4}>
            <Text fontWeight="semibold" fontSize="md" color="fg.muted">
                Изображения контента
            </Text>

            <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                <FormField label="Изображение для списка" error={errors.list_item}>
                    <ImageUploader
                        onChange={(file) => setData('list_item', file)}
                        existingUrl={existing.list_image || null}
                        error={errors.list_item}
                        maxSize={20}
                        placeholder="Изображение для списка"
                    />
                </FormField>

                <FormField label="Изображение (десктоп)" error={errors.detail_desktop}>
                    <ImageUploader
                        onChange={(file) => setData('detail_desktop', file)}
                        existingUrl={existing.detail_desktop_image || null}
                        error={errors.detail_desktop}
                        maxSize={20}
                        placeholder="Десктопное изображение"
                    />
                </FormField>

                <FormField label="Изображение (мобильное)" error={errors.detail_mobile}>
                    <ImageUploader
                        onChange={(file) => setData('detail_mobile', file)}
                        existingUrl={existing.detail_mobile_image || null}
                        error={errors.detail_mobile}
                        maxSize={20}
                        placeholder="Мобильное изображение"
                    />
                </FormField>
            </SimpleGrid>
        </Stack>
    );
};
