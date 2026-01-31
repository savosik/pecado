import React, { useState, useRef } from 'react';
import {
    Box,
    Image,
    Text,
    VStack,
    HStack,
    IconButton,
    Input,
    SimpleGrid,
} from '@chakra-ui/react';
import { LuUpload, LuX, LuImage } from 'react-icons/lu';

/**
 * MultipleImageUploader - компонент для загрузки множественных изображений с превью
 * 
 * @param {string} name - Имя поля
 * @param {File[]} value - Массив выбранных файлов
 * @param {Function} onChange - Callback изменения (files[])
 * @param {Array} existingImages - Существующие изображения [{id, url}]
 * @param {Function} onRemoveExisting - Callback для удаления существующих (id)
 * @param {string} error - Текст ошибки
 * @param {string} label - Label поля
 * @param {number} maxFiles - Максимальное количество файлов
 * @param {number} maxSize - Максимальный размер в MB
 * @param {string[]} acceptedTypes - Допустимые типы файлов
 */
export const MultipleImageUploader = ({
    name,
    value = [],
    onChange,
    existingImages = [],
    onRemoveExisting,
    error = null,
    label = 'Изображения',
    maxFiles = 10,
    maxSize = 10,
    acceptedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
}) => {
    const [previews, setPreviews] = useState([]);
    const [uploadError, setUploadError] = useState(null);
    const [isDragging, setIsDragging] = useState(false);
    const inputRef = useRef(null);

    const handleFilesSelect = (files) => {
        if (!files || files.length === 0) return;

        const currentCount = value.length + existingImages.length;
        const newFilesArray = Array.from(files);

        // Проверка максимального количества
        if (currentCount + newFilesArray.length > maxFiles) {
            setUploadError(`Максимальное количество изображений: ${maxFiles}`);
            return;
        }

        const validFiles = [];
        const newPreviews = [];

        for (const file of newFilesArray) {
            // Валидация размера
            if (file.size > maxSize * 1024 * 1024) {
                setUploadError(`Файл ${file.name} слишком большой. Максимум: ${maxSize}MB`);
                continue;
            }

            // Валидация типа
            if (!acceptedTypes.includes(file.type)) {
                setUploadError(`Файл ${file.name} имеет недопустимый формат`);
                continue;
            }

            validFiles.push(file);

            // Создание превью
            const reader = new FileReader();
            reader.onloadend = () => {
                newPreviews.push(reader.result);
                if (newPreviews.length === validFiles.length) {
                    setPreviews([...previews, ...newPreviews]);
                }
            };
            reader.readAsDataURL(file);
        }

        if (validFiles.length > 0) {
            setUploadError(null);
            onChange([...value, ...validFiles]);
        }
    };

    const handleInputChange = (e) => {
        const files = e.target.files;
        handleFilesSelect(files);
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setIsDragging(false);
        const files = e.dataTransfer.files;
        handleFilesSelect(files);
    };

    const handleDragOver = (e) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = () => {
        setIsDragging(false);
    };

    const handleRemoveNew = (index) => {
        const newFiles = [...value];
        newFiles.splice(index, 1);

        const newPreviews = [...previews];
        newPreviews.splice(index, 1);

        setPreviews(newPreviews);
        onChange(newFiles);
        setUploadError(null);
    };

    const handleClick = () => {
        inputRef.current?.click();
    };

    return (
        <Box>
            {label && (
                <Text fontWeight="medium" mb={2}>
                    {label}
                </Text>
            )}

            <Input
                ref={inputRef}
                type="file"
                name={name}
                accept={acceptedTypes.join(',')}
                onChange={handleInputChange}
                display="none"
                multiple
            />

            {/* Существующие изображения */}
            {existingImages.length > 0 && (
                <Box mb={4}>
                    <Text fontSize="sm" fontWeight="medium" color="fg.muted" mb={2}>
                        Текущие изображения:
                    </Text>
                    <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                        {existingImages.map((img, index) => (
                            <Box key={img.id || index} position="relative">
                                <Image
                                    src={img.url}
                                    alt={`Изображение ${index + 1}`}
                                    w="full"
                                    aspectRatio={2 / 3}
                                    objectFit="cover"
                                    borderRadius="md"
                                    borderWidth="1px"
                                    borderColor="border.muted"
                                />
                                {onRemoveExisting && (
                                    <IconButton
                                        position="absolute"
                                        top={1}
                                        right={1}
                                        size="xs"
                                        colorPalette="red"
                                        onClick={() => onRemoveExisting(img.id)}
                                        aria-label="Удалить изображение"
                                    >
                                        <LuX />
                                    </IconButton>
                                )}
                            </Box>
                        ))}
                    </SimpleGrid>
                </Box>
            )}

            {/* Новые изображения (превью) */}
            {previews.length > 0 && (
                <Box mb={4}>
                    <Text fontSize="sm" fontWeight="medium" color="fg.muted" mb={2}>
                        Новые изображения:
                    </Text>
                    <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                        {previews.map((preview, index) => (
                            <Box key={index} position="relative">
                                <Image
                                    src={preview}
                                    alt={`Новое изображение ${index + 1}`}
                                    w="full"
                                    aspectRatio={2 / 3}
                                    objectFit="cover"
                                    borderRadius="md"
                                    borderWidth="1px"
                                    borderColor="border.muted"
                                />
                                <IconButton
                                    position="absolute"
                                    top={1}
                                    right={1}
                                    size="xs"
                                    colorPalette="red"
                                    onClick={() => handleRemoveNew(index)}
                                    aria-label="Удалить изображение"
                                >
                                    <LuX />
                                </IconButton>
                            </Box>
                        ))}
                    </SimpleGrid>
                </Box>
            )}

            {/* Dropzone для загрузки */}
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
                p={6}
                textAlign="center"
                cursor="pointer"
                _hover={{ bg: 'bg.muted' }}
                transition="all 0.2s"
            >
                <VStack gap={2}>
                    <Box color="fg.muted" fontSize="2xl">
                        <LuUpload />
                    </Box>
                    <VStack gap={1}>
                        <Text fontWeight="medium" fontSize="sm">
                            Перетащите изображения сюда
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            или нажмите для выбора файлов
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            Максимум: {maxFiles} файлов, {maxSize}MB каждый
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            Загружено: {value.length + existingImages.length} / {maxFiles}
                        </Text>
                    </VStack>
                </VStack>
            </Box>

            {/* Ошибки */}
            {(error || uploadError) && (
                <Text color="red.500" fontSize="sm" mt={2}>
                    {error || uploadError}
                </Text>
            )}
        </Box>
    );
};
