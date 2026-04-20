import React, { useState, useRef } from 'react';
import { router } from '@inertiajs/react';
import { Box, VStack, HStack, Text, Button, IconButton } from '@chakra-ui/react';
import { LuPaperclip, LuTrash2, LuDownload, LuUpload } from 'react-icons/lu';

export default function AttachmentSection({ task }) {
    const [selectedFiles, setSelectedFiles] = useState([]);
    const [uploading, setUploading] = useState(false);
    const fileInputRef = useRef(null);

    const attachments = task?.attachments || [];

    const handleFileChange = (e) => {
        setSelectedFiles(Array.from(e.target.files));
    };

    const handleUpload = () => {
        if (!selectedFiles.length || uploading) return;

        const formData = new FormData();
        selectedFiles.forEach(file => formData.append('files[]', file));

        setUploading(true);
        router.post(route('admin.kanban.attachments.store', task.id), formData, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setSelectedFiles([]);
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
            onFinish: () => setUploading(false),
        });
    };

    const handleDelete = (attachmentId) => {
        router.delete(route('admin.kanban.attachments.destroy', attachmentId), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const formatSize = (bytes) => {
        if (bytes < 1024) return `${bytes} Б`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`;
    };

    return (
        <Box mt={6} pt={6} borderTopWidth="1px" borderColor="border.default">
            <Text fontWeight="bold" mb={4}>Вложения ({attachments.length})</Text>

            {attachments.length > 0 && (
                <VStack align="stretch" gap={2} mb={4}>
                    {attachments.map(att => (
                        <HStack
                            key={att.id}
                            p={2}
                            bg="gray.50"
                            _dark={{ bg: 'gray.700' }}
                            rounded="md"
                            justify="space-between"
                        >
                            <HStack gap={2} flex={1} minW={0}>
                                <LuPaperclip style={{ flexShrink: 0 }} />
                                <Text fontSize="sm" truncate flex={1}>{att.original_name}</Text>
                                <Text fontSize="xs" color="fg.muted" flexShrink={0}>
                                    {formatSize(att.size)}
                                </Text>
                            </HStack>
                            <HStack gap={1}>
                                <a href={att.url} target="_blank" rel="noopener noreferrer">
                                    <IconButton size="xs" variant="ghost" aria-label="Скачать">
                                        <LuDownload />
                                    </IconButton>
                                </a>
                                <IconButton
                                    size="xs"
                                    variant="ghost"
                                    colorPalette="red"
                                    aria-label="Удалить"
                                    onClick={() => handleDelete(att.id)}
                                >
                                    <LuTrash2 />
                                </IconButton>
                            </HStack>
                        </HStack>
                    ))}
                </VStack>
            )}

            <HStack>
                <Box flex={1}>
                    <input
                        ref={fileInputRef}
                        type="file"
                        multiple
                        onChange={handleFileChange}
                        style={{ width: '100%', fontSize: '14px' }}
                    />
                </Box>
                <Button
                    colorPalette="blue"
                    size="sm"
                    onClick={handleUpload}
                    disabled={!selectedFiles.length || uploading}
                    loading={uploading}
                    loadingText="Загрузка..."
                >
                    <LuUpload /> Загрузить
                </Button>
            </HStack>
        </Box>
    );
}
