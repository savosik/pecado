import React, { useState, useRef } from 'react';
import {
    Box,
    Text,
    VStack,
    IconButton,
    Input,
    HStack,
} from '@chakra-ui/react';
import { LuUpload, LuX, LuVideo, LuPlay } from 'react-icons/lu';

/**
 * VideoUploader - компонент для загрузки видео с превью
 * 
 * @param {string} name - Имя поля
 * @param {File} value - Выбранный файл
 * @param {Function} onChange - Callback изменения (file)
 * @param {string} existingVideo - URL существующего видео
 * @param {Function} onRemoveExisting - Callback для удаления существующего
 * @param {string} error - Текст ошибки
 * @param {string} label - Label поля
 * @param {number} maxSize - Максимальный размер в MB
 * @param {string[]} acceptedTypes - Допустимые типы файлов
 */
export const VideoUploader = ({
    name,
    value = null,
    onChange,
    existingVideo = null,
    onRemoveExisting,
    error = null,
    label = 'Видео',
    maxSize = 50,
    acceptedTypes = ['video/mp4', 'video/webm', 'video/quicktime'],
}) => {
    const [preview, setPreview] = useState(null);
    const [uploadError, setUploadError] = useState(null);
    const [isDragging, setIsDragging] = useState(false);
    const [fileInfo, setFileInfo] = useState(null);
    const inputRef = useRef(null);

    const formatFileSize = (bytes) => {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    };

    const handleFileSelect = (file) => {
        if (!file) return;

        // Валидация размера
        if (file.size > maxSize * 1024 * 1024) {
            setUploadError(`Файл слишком большой. Максимальный размер: ${maxSize}MB`);
            return;
        }

        // Валидация типа
        if (!acceptedTypes.includes(file.type)) {
            setUploadError('Недопустимый формат видео. Поддерживаются: MP4, WebM, MOV');
            return;
        }

        setUploadError(null);
        setFileInfo({
            name: file.name,
            size: formatFileSize(file.size),
        });

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
        setFileInfo(null);
        setUploadError(null);
        onChange(null);
        if (inputRef.current) {
            inputRef.current.value = '';
        }
    };

    const handleRemoveExistingVideo = () => {
        if (onRemoveExisting) {
            onRemoveExisting();
        }
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
            />

            {/* Существующее видео */}
            {existingVideo && !preview && (
                <Box mb={4}>
                    <Text fontSize="sm" fontWeight="medium" color="fg.muted" mb={2}>
                        Текущее видео:
                    </Text>
                    <Box position="relative" display="inline-block" w="full" maxW="500px">
                        <video
                            src={existingVideo}
                            controls
                            style={{
                                width: '100%',
                                maxHeight: '300px',
                                borderRadius: '8px',
                                border: '1px solid',
                                borderColor: 'var(--chakra-colors-border-muted)',
                            }}
                        />
                        {onRemoveExisting && (
                            <IconButton
                                position="absolute"
                                top={2}
                                right={2}
                                size="sm"
                                colorPalette="red"
                                onClick={handleRemoveExistingVideo}
                                aria-label="Удалить видео"
                            >
                                <LuX />
                            </IconButton>
                        )}
                    </Box>
                </Box>
            )}

            {/* Превью нового видео */}
            {preview && (
                <Box mb={4}>
                    <Text fontSize="sm" fontWeight="medium" color="fg.muted" mb={2}>
                        Новое видео:
                    </Text>
                    <Box position="relative" display="inline-block" w="full" maxW="500px">
                        <video
                            src={preview}
                            controls
                            style={{
                                width: '100%',
                                maxHeight: '300px',
                                borderRadius: '8px',
                                border: '1px solid',
                                borderColor: 'var(--chakra-colors-border-muted)',
                            }}
                        />
                        <IconButton
                            position="absolute"
                            top={2}
                            right={2}
                            size="sm"
                            colorPalette="red"
                            onClick={handleRemove}
                            aria-label="Удалить видео"
                        >
                            <LuX />
                        </IconButton>
                        {fileInfo && (
                            <Box
                                position="absolute"
                                bottom={2}
                                left={2}
                                bg="blackAlpha.700"
                                color="white"
                                px={2}
                                py={1}
                                borderRadius="md"
                                fontSize="xs"
                            >
                                {fileInfo.name} ({fileInfo.size})
                            </Box>
                        )}
                    </Box>
                </Box>
            )}

            {/* Dropzone для загрузки */}
            {!preview && (
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
                            <LuVideo />
                        </Box>
                        <VStack gap={1}>
                            <Text fontWeight="medium" fontSize="sm">
                                Перетащите видео сюда
                            </Text>
                            <Text fontSize="xs" color="fg.muted">
                                или нажмите для выбора файла
                            </Text>
                            <Text fontSize="xs" color="fg.muted">
                                Максимальный размер: {maxSize}MB
                            </Text>
                            <Text fontSize="xs" color="fg.muted">
                                Поддерживаемые форматы: MP4, WebM, MOV
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
