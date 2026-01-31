import React, { useState, useRef } from 'react';
import {
    Box,
    Image,
    Text,
    VStack,
    IconButton,
    Input,
    Badge,
} from '@chakra-ui/react';
import { LuX, LuImage, LuRefreshCw } from 'react-icons/lu';

/**
 * ImageUploader - универсальный компонент для загрузки изображений
 * 
 * Поддерживает:
 * - Загрузку нового файла с локальным превью
 * - Отображение существующего изображения с сервера
 * - Удаление существующего изображения
 * - Замену существующего изображения новым
 * - Drag & Drop
 * - Валидацию размера и типа файла
 * 
 * @param {string} name - Имя поля для формы
 * @param {Function} onChange - Callback при выборе нового файла (file | null)
 * @param {string} existingUrl - URL существующего изображения с сервера
 * @param {Function} onRemoveExisting - Callback для удаления существующего изображения
 * @param {string} error - Текст ошибки валидации
 * @param {number} maxSize - Максимальный размер файла в MB (default: 5)
 * @param {string[]} acceptedTypes - Допустимые MIME-типы
 * @param {string} aspectRatio - Соотношение сторон превью (default: "auto")
 * @param {string} maxPreviewWidth - Максимальная ширина превью (default: "300px")
 * @param {string} placeholder - Текст плейсхолдера
 */
export const ImageUploader = ({
    name,
    onChange,
    existingUrl = null,
    onRemoveExisting = null,
    error = null,
    maxSize = 5,
    acceptedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    aspectRatio = 'auto',
    maxPreviewWidth = '300px',
    placeholder = 'Перетащите изображение сюда',
}) => {
    // Локальное превью нового выбранного файла
    const [localPreview, setLocalPreview] = useState(null);
    const [uploadError, setUploadError] = useState(null);
    const [isDragging, setIsDragging] = useState(false);
    const inputRef = useRef(null);

    // Определяем, что показывать: локальное превью имеет приоритет
    const displayUrl = localPreview || existingUrl;
    const isExisting = !localPreview && existingUrl;
    const isNew = !!localPreview;

    const handleFileSelect = (file) => {
        if (!file) return;

        // Валидация размера
        if (file.size > maxSize * 1024 * 1024) {
            setUploadError(`Файл слишком большой. Максимальный размер: ${maxSize}MB`);
            return;
        }

        // Валидация типа
        if (!acceptedTypes.includes(file.type)) {
            setUploadError('Недопустимый формат файла');
            return;
        }

        setUploadError(null);

        // Создание локального превью
        const reader = new FileReader();
        reader.onloadend = () => {
            setLocalPreview(reader.result);
        };
        reader.readAsDataURL(file);

        // Передача файла родителю
        onChange?.(file);
    };

    const handleInputChange = (e) => {
        const file = e.target.files?.[0];
        handleFileSelect(file);
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setIsDragging(false);
        const file = e.dataTransfer.files?.[0];
        handleFileSelect(file);
    };

    const handleDragOver = (e) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = () => {
        setIsDragging(false);
    };

    // Удаление локального превью (отмена выбора нового файла)
    const handleRemoveLocal = () => {
        setLocalPreview(null);
        setUploadError(null);
        onChange?.(null);
        if (inputRef.current) {
            inputRef.current.value = '';
        }
    };

    // Удаление существующего изображения с сервера
    const handleRemoveExisting = () => {
        if (onRemoveExisting) {
            onRemoveExisting();
        }
    };

    // Замена изображения — открыть диалог выбора файла
    const handleReplace = () => {
        inputRef.current?.click();
    };

    const handleClick = () => {
        inputRef.current?.click();
    };

    return (
        <Box>
            <Input
                ref={inputRef}
                type="file"
                name={name}
                accept={acceptedTypes.join(',')}
                onChange={handleInputChange}
                display="none"
            />

            {displayUrl ? (
                // Превью изображения (локальное или существующее)
                <Box position="relative" display="inline-block">
                    <Image
                        src={displayUrl}
                        alt="Превью"
                        maxW={maxPreviewWidth}
                        aspectRatio={aspectRatio}
                        objectFit="cover"
                        borderRadius="md"
                        borderWidth="1px"
                        borderColor="border.muted"
                    />

                    {/* Бейдж статуса */}
                    {isNew && (
                        <Badge
                            position="absolute"
                            top={2}
                            left={2}
                            colorPalette="green"
                            size="sm"
                        >
                            Новое
                        </Badge>
                    )}

                    {/* Кнопки управления */}
                    <Box
                        position="absolute"
                        top={2}
                        right={2}
                        display="flex"
                        gap={1}
                    >
                        {/* Кнопка замены */}
                        <IconButton
                            size="xs"
                            colorPalette="blue"
                            variant="solid"
                            onClick={handleReplace}
                            aria-label="Заменить изображение"
                            title="Заменить"
                        >
                            <LuRefreshCw />
                        </IconButton>

                        {/* Кнопка удаления */}
                        <IconButton
                            size="xs"
                            colorPalette="red"
                            variant="solid"
                            onClick={isNew ? handleRemoveLocal : handleRemoveExisting}
                            aria-label="Удалить изображение"
                            title="Удалить"
                            disabled={isExisting && !onRemoveExisting}
                        >
                            <LuX />
                        </IconButton>
                    </Box>
                </Box>
            ) : (
                // Dropzone для загрузки
                <Box
                    onClick={handleClick}
                    onDrop={handleDrop}
                    onDragOver={handleDragOver}
                    onDragLeave={handleDragLeave}
                    borderWidth="2px"
                    borderStyle="dashed"
                    borderColor={
                        error || uploadError
                            ? 'red.500'
                            : isDragging
                                ? 'blue.400'
                                : 'border.muted'
                    }
                    bg={isDragging ? 'blue.50' : 'transparent'}
                    borderRadius="md"
                    p={8}
                    textAlign="center"
                    cursor="pointer"
                    _hover={{ bg: 'bg.muted' }}
                    transition="all 0.2s"
                >
                    <VStack gap={3}>
                        <Box color="fg.muted" fontSize="3xl">
                            <LuImage />
                        </Box>
                        <VStack gap={1}>
                            <Text fontWeight="medium">
                                {placeholder}
                            </Text>
                            <Text fontSize="sm" color="fg.muted">
                                или нажмите для выбора файла
                            </Text>
                            <Text fontSize="xs" color="fg.muted">
                                Максимальный размер: {maxSize}MB
                            </Text>
                        </VStack>
                    </VStack>
                </Box>
            )}

            {/* Ошибки */}
            {(error || uploadError) && (
                <Text color="red.500" fontSize="sm" mt={2}>
                    {error || uploadError}
                </Text>
            )}
        </Box>
    );
};
