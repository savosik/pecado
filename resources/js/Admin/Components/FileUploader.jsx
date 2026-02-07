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
    Icon,
} from '@chakra-ui/react';
import { LuUpload, LuX, LuFile, LuImage } from 'react-icons/lu';

/**
 * FileUploader - Component for uploading multiple files with preview/list
 *
 * @param {string} name - Field name
 * @param {File[]} value - Array of selected new files
 * @param {Function} onChange - Callback for changes (files[])
 * @param {Array} existingFiles - Existing files [{id, url, name, mime_type, size}]
 * @param {Function} onRemoveExisting - Callback to remove existing files (id)
 * @param {string} error - Error message
 * @param {string} label - Field label
 * @param {number} maxFiles - Max number of files
 * @param {number} maxSize - Max size in MB
 * @param {string[]} acceptedTypes - Accepted file types
 */
export const FileUploader = ({
    name,
    value: rawValue = [],
    onChange,
    existingFiles: rawExistingFiles = [],
    onRemoveExisting,
    error = null,
    label = 'Файлы',
    maxFiles = 10,
    maxSize = 10, // MB
    acceptedTypes = [], // Empty = all
}) => {
    const value = rawValue ?? [];
    const existingFiles = rawExistingFiles ?? [];
    const [uploadError, setUploadError] = useState(null);
    const [isDragging, setIsDragging] = useState(false);
    const inputRef = useRef(null);

    const handleFilesSelect = (files) => {
        if (!files || files.length === 0) return;

        const currentCount = value.length + existingFiles.length;
        const newFilesArray = Array.from(files);

        // Max files check
        if (currentCount + newFilesArray.length > maxFiles) {
            setUploadError(`Максимальное количество файлов: ${maxFiles}`);
            return;
        }

        const validFiles = [];

        for (const file of newFilesArray) {
            // Size validation
            if (file.size > maxSize * 1024 * 1024) {
                setUploadError(`Файл ${file.name} слишком большой. Максимум: ${maxSize}MB`);
                continue;
            }

            // Type validation (if acceptedTypes provided)
            if (acceptedTypes.length > 0 && !acceptedTypes.some(type => {
                const mime = type.replace('*', '');
                return file.type.startsWith(mime);
            })) {
                setUploadError(`Файл ${file.name} имеет недопустимый формат`);
                continue;
            }

            validFiles.push(file);
        }

        if (validFiles.length > 0) {
            setUploadError(null);
            onChange([...value, ...validFiles]);
        }
    };

    const handleInputChange = (e) => {
        const files = e.target.files;
        handleFilesSelect(files);
        // Reset input value to allow selecting same file again if needed (though usually not for multi)
        if (inputRef.current) inputRef.current.value = '';
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
        onChange(newFiles);
        setUploadError(null);
    };

    const handleClick = () => {
        inputRef.current?.click();
    };

    const formatSize = (bytes) => {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };

    const isImage = (mimeType) => {
        return mimeType?.startsWith('image/');
    };

    const getFileIcon = (mimeType) => {
        if (isImage(mimeType)) return LuImage;
        return LuFile;
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
                required={false}
            />

            <VStack gap={3} align="stretch">
                {/* Existing Files List */}
                {existingFiles.length > 0 && (
                    <VStack align="stretch" gap={2}>
                        <Text fontSize="sm" fontWeight="medium" color="fg.muted">
                            Текущие файлы:
                        </Text>
                        {existingFiles.map((file) => (
                            <HStack
                                key={file.id}
                                p={3}
                                borderWidth="1px"
                                borderRadius="md"
                                justify="space-between"
                                bg="bg.subtle"
                            >
                                <HStack gap={3} overflow="hidden">
                                    {isImage(file.mime_type) ? (
                                        <Image
                                            src={file.url}
                                            alt={file.name}
                                            boxSize="40px"
                                            objectFit="cover"
                                            borderRadius="sm"
                                        />
                                    ) : (
                                        <Box p={2} bg="bg.muted" borderRadius="sm">
                                            <Icon as={LuFile} fontSize="xl" />
                                        </Box>
                                    )}
                                    <VStack align="start" gap={0} overflow="hidden">
                                        <Text fontSize="sm" fontWeight="medium" truncate maxW="200px">
                                            {file.name}
                                        </Text>
                                        <Text fontSize="xs" color="fg.muted">
                                            {formatSize(file.size)}
                                        </Text>
                                    </VStack>
                                </HStack>
                                {onRemoveExisting && (
                                    <IconButton
                                        size="xs"
                                        variant="ghost"
                                        colorPalette="red"
                                        onClick={() => onRemoveExisting(file.id)}
                                        aria-label="Удалить файл"
                                    >
                                        <LuX />
                                    </IconButton>
                                )}
                            </HStack>
                        ))}
                    </VStack>
                )}

                {/* New Files List */}
                {value.length > 0 && (
                    <VStack align="stretch" gap={2}>
                        <Text fontSize="sm" fontWeight="medium" color="fg.muted">
                            Новые файлы:
                        </Text>
                        {value.map((file, index) => (
                            <HStack
                                key={index}
                                p={3}
                                borderWidth="1px"
                                borderRadius="md"
                                justify="space-between"
                                borderColor="blue.subtle"
                                bg="blue.subtle/10"
                            >
                                <HStack gap={3} overflow="hidden">
                                    <Box p={2} bg="bg.muted" borderRadius="sm">
                                        <Icon as={getFileIcon(file.type)} fontSize="xl" />
                                    </Box>
                                    <VStack align="start" gap={0} overflow="hidden">
                                        <Text fontSize="sm" fontWeight="medium" truncate maxW="200px">
                                            {file.name}
                                        </Text>
                                        <Text fontSize="xs" color="fg.muted">
                                            {formatSize(file.size)}
                                        </Text>
                                    </VStack>
                                </HStack>
                                <IconButton
                                    size="xs"
                                    variant="ghost"
                                    colorPalette="red"
                                    onClick={() => handleRemoveNew(index)}
                                    aria-label="Удалить файл"
                                >
                                    <LuX />
                                </IconButton>
                            </HStack>
                        ))}
                    </VStack>
                )}

                {/* Dropzone */}
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
                                Перетащите файлы сюда
                            </Text>
                            <Text fontSize="xs" color="fg.muted">
                                или нажмите для выбора
                            </Text>
                            <Text fontSize="xs" color="fg.muted">
                                Максимум: {maxFiles} файлов, {maxSize}MB каждый
                            </Text>
                        </VStack>
                    </VStack>
                </Box>

                {/* Errors */}
                {(error || uploadError) && (
                    <Text color="red.500" fontSize="sm">
                        {error || uploadError}
                    </Text>
                )}
            </VStack>
        </Box>
    );
};
