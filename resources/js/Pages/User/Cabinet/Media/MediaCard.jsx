import { useState } from 'react';
import {
    Box, Card, Image, Text, HStack, Badge, VStack, IconButton, Checkbox,
} from '@chakra-ui/react';
import { LuDownload, LuImage, LuVideo, LuFileText, LuFile, LuEye } from 'react-icons/lu';

export default function MediaCard({ item, selected, onToggleSelection, onOpenLightbox }) {
    const [imageError, setImageError] = useState(false);

    const isImage = item.mime_type?.startsWith('image/');
    const isVideo = item.mime_type?.startsWith('video/');

    const formatFileSize = (bytes) => {
        if (!bytes) return '0 Б';
        if (bytes < 1024) return `${bytes} Б`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`;
    };

    const getMimeTypeIcon = (mimeType) => {
        if (!mimeType) return LuFile;
        if (mimeType.startsWith('image/')) return LuImage;
        if (mimeType.startsWith('video/')) return LuVideo;
        if (mimeType.includes('pdf') || mimeType.startsWith('text/')) return LuFileText;
        return LuFile;
    };

    const Icon = getMimeTypeIcon(item.mime_type);

    const handleDownload = (e) => {
        e.stopPropagation();
        if (item.download_url) {
            window.open(item.download_url, '_blank');
        }
    };

    return (
        <Card.Root bg={{ base: 'white', _dark: 'gray.800' }}
            overflow="hidden"
            borderWidth={selected ? '2px' : '1px'}
            borderColor={selected ? 'blue.500' : 'gray.200'}
            _dark={{
                borderColor: selected ? 'blue.400' : 'gray.700',
            }}
            transition="all 0.15s"
            _hover={{
                borderColor: selected ? 'blue.500' : 'gray.300',
                _dark: { borderColor: selected ? 'blue.400' : 'gray.600' },
                shadow: 'md',
            }}
            role="group"
        >
            {/* Preview area */}
            <Box
                position="relative"
                aspectRatio="1"
                bg="gray.100"
                _dark={{ bg: 'gray.800' }}
                cursor={isImage ? 'pointer' : 'default'}
                onClick={isImage ? onOpenLightbox : undefined}
            >
                {isImage && !imageError ? (
                    <Image
                        src={item.thumbnail_url || item.original_url}
                        alt={item.file_name}
                        width="100%"
                        height="100%"
                        objectFit="cover"
                        onError={() => setImageError(true)}
                    />
                ) : isVideo ? (
                    <VStack height="100%" justify="center" align="center" color="gray.400">
                        <Box fontSize="4xl"><LuVideo /></Box>
                        <Text fontSize="xs" color="gray.500">Видео</Text>
                    </VStack>
                ) : (
                    <VStack height="100%" justify="center" align="center" color="gray.400">
                        <Box fontSize="4xl"><Icon /></Box>
                    </VStack>
                )}

                {/* Checkbox — top left */}
                <Box
                    position="absolute"
                    top="2"
                    left="2"
                    onClick={(e) => e.stopPropagation()}
                >
                    <Checkbox.Root
                        size="md"
                        checked={selected}
                        onCheckedChange={() => onToggleSelection(item.id)}
                    >
                        <Checkbox.HiddenInput />
                        <Checkbox.Control
                            bg={{ base: 'white', _dark: 'gray.800' }}
                            _dark={{ bg: 'gray.800' }}
                            shadow="sm"
                        />
                    </Checkbox.Root>
                </Box>

                {/* Download — top right, visible on hover */}
                <Box
                    position="absolute"
                    top="2"
                    right="2"
                    opacity="0"
                    _groupHover={{ opacity: 1 }}
                    transition="opacity 0.2s"
                    onClick={(e) => e.stopPropagation()}
                >
                    <IconButton
                        aria-label="Скачать"
                        size="sm"
                        variant="solid"
                        bg={{ base: 'white', _dark: 'gray.800' }}
                        color="gray.700"
                        shadow="md"
                        _hover={{ bg: 'gray.100' }}
                        _dark={{ bg: 'gray.700', color: 'white', _hover: { bg: 'gray.600' } }}
                        onClick={handleDownload}
                    >
                        <LuDownload size={14} />
                    </IconButton>
                </Box>

                {/* Preview hint on hover */}
                {isImage && (
                    <Box
                        position="absolute"
                        bottom="2"
                        right="2"
                        opacity="0"
                        _groupHover={{ opacity: 1 }}
                        transition="opacity 0.2s"
                        bg="blackAlpha.600"
                        borderRadius="full"
                        p="1.5"
                    >
                        <LuEye color="white" size={14} />
                    </Box>
                )}
            </Box>

            {/* Info */}
            <Card.Body gap="1.5" p="3">
                <Text
                    fontSize="sm"
                    fontWeight="600"
                    noOfLines={1}
                    title={item.file_name}
                >
                    {item.file_name}
                </Text>

                <HStack flexWrap="wrap" gap="1">
                    <Badge size="sm" colorPalette="blue">
                        {formatFileSize(item.size)}
                    </Badge>
                    {item.collection_name && (
                        <Badge size="sm" colorPalette="purple">
                            {item.collection_name}
                        </Badge>
                    )}
                </HStack>

                {item.model_type_label && (
                    <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.400' }} noOfLines={1}>
                        {item.model_type_label}
                        {item.owner_display_name && `: ${item.owner_display_name}`}
                    </Text>
                )}

                <Text fontSize="xs" color="gray.500">
                    {new Date(item.created_at).toLocaleDateString('ru-RU')}
                </Text>
            </Card.Body>
        </Card.Root>
    );
}
