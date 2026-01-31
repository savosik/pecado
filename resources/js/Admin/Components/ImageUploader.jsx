import React, { useState, useRef } from 'react';
import {
    Box,
    Image,
    Text,
    VStack,
    IconButton,
    Input,
} from '@chakra-ui/react';
import { LuUpload, LuX, LuImage } from 'react-icons/lu';

/**
 * ImageUploader - компонент для загрузки изображений с превью
 * 
 * @param {string} name - Имя поля
 * @param {string} value - URL текущего изображения
 * @param {Function} onChange - Callback изменения (file)
 * @param {string} error - Текст ошибки
 * @param {number} maxSize - Максимальный размер в MB
 * @param {string[]} acceptedTypes - Допустимые типы файлов
 */
export const ImageUploader = ({
    name,
    value = null,
    onChange,
    error = null,
    maxSize = 5,
    acceptedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
}) => {
    const [preview, setPreview] = useState(value);
    const [uploadError, setUploadError] = useState(null);
    const [isDragging, setIsDragging] = useState(false);
    const inputRef = useRef(null);

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

        // Создание превью
        const reader = new FileReader();
        reader.onloadend = () => {
            setPreview(reader.result);
        };
        reader.readAsDataURL(file);

        // Передача файла родителю
        onChange(file);
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

    const handleRemove = () => {
        setPreview(null);
        setUploadError(null);
        onChange(null);
        if (inputRef.current) {
            inputRef.current.value = '';
        }
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

            {preview ? (
                // Превью загруженного изображения
                <Box position="relative" display="inline-block">
                    <Image
                        src={preview}
                        alt="Превью"
                        maxW="300px"
                        aspectRatio={2 / 3}
                        objectFit="cover"
                        borderRadius="md"
                        borderWidth="1px"
                        borderColor="border.muted"
                    />
                    <IconButton
                        position="absolute"
                        top={2}
                        right={2}
                        size="sm"
                        colorPalette="red"
                        onClick={handleRemove}
                        aria-label="Удалить изображение"
                    >
                        <LuX />
                    </IconButton>
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
                                Перетащите изображение сюда
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
