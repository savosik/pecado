import { useState, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import {
    Box, Flex, HStack, Text, IconButton, Badge, VStack,
} from '@chakra-ui/react';
import { LuX, LuChevronLeft, LuChevronRight, LuDownload, LuZoomIn, LuZoomOut, LuVideo, LuFileText, LuFile } from 'react-icons/lu';

export default function MediaLightbox({ media, initialIndex, isOpen, onClose }) {
    const [currentIndex, setCurrentIndex] = useState(initialIndex);
    const [zoom, setZoom] = useState(1);

    const currentMedia = media[currentIndex];
    const isImage = currentMedia?.mime_type?.startsWith('image/');
    const isVideo = currentMedia?.mime_type?.startsWith('video/');

    useEffect(() => {
        setCurrentIndex(initialIndex);
        setZoom(1);
    }, [initialIndex]);

    const handlePrevious = useCallback(() => {
        setCurrentIndex(prev => (prev > 0 ? prev - 1 : media.length - 1));
        setZoom(1);
    }, [media.length]);

    const handleNext = useCallback(() => {
        setCurrentIndex(prev => (prev < media.length - 1 ? prev + 1 : 0));
        setZoom(1);
    }, [media.length]);

    const handleDownload = useCallback(() => {
        if (!currentMedia) return;
        const url = currentMedia.download_url || currentMedia.original_url;
        if (url) {
            const link = document.createElement('a');
            link.href = url;
            link.download = currentMedia.file_name || 'download';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }, [currentMedia]);

    const handleZoomIn = () => setZoom(prev => Math.min(prev + 0.25, 3));
    const handleZoomOut = () => setZoom(prev => Math.max(prev - 0.25, 0.5));

    // Keyboard navigation
    useEffect(() => {
        if (!isOpen) return;

        const handleKeyDown = (e) => {
            if (e.key === 'ArrowLeft') handlePrevious();
            if (e.key === 'ArrowRight') handleNext();
            if (e.key === 'Escape') onClose();
            if (e.key === '+' || e.key === '=') handleZoomIn();
            if (e.key === '-') handleZoomOut();
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [isOpen, handlePrevious, handleNext, onClose]);

    // Lock body scroll
    useEffect(() => {
        if (isOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
        return () => { document.body.style.overflow = ''; };
    }, [isOpen]);

    const formatBytes = (bytes) => {
        if (!bytes || bytes === 0) return '0 Б';
        const k = 1024;
        const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };

    if (!currentMedia || !isOpen) return null;

    const lightboxContent = (
        <>
            {/* Overlay */}
            <Box
                position="fixed"
                inset="0"
                zIndex="9998"
                bg="blackAlpha.900"
                onClick={onClose}
            />

            {/* Content */}
            <Flex
                position="fixed"
                inset="0"
                zIndex="9999"
                direction="column"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header */}
                <Flex
                    position="relative"
                    zIndex="10"
                    align="center"
                    justify="space-between"
                    px="5"
                    py="3"
                    bg="blackAlpha.700"
                    backdropFilter="blur(8px)"
                >
                    <Box flex="1" minW="0">
                        <Text color="white" fontWeight="600" noOfLines={1}>
                            {currentMedia.file_name}
                        </Text>
                        <HStack gap="2" mt="1" fontSize="sm" color="gray.300">
                            <Text>{formatBytes(currentMedia.size)}</Text>
                            <Text>•</Text>
                            <Text>{currentIndex + 1} / {media.length}</Text>
                            {currentMedia.mime_type && (
                                <>
                                    <Text>•</Text>
                                    <Text>{currentMedia.mime_type}</Text>
                                </>
                            )}
                        </HStack>
                    </Box>
                    <HStack gap="1">
                        <IconButton
                            aria-label="Скачать"
                            variant="ghost"
                            size="sm"
                            color="white"
                            _hover={{ bg: 'whiteAlpha.200' }}
                            onClick={handleDownload}
                        >
                            <LuDownload />
                        </IconButton>
                        <IconButton
                            aria-label="Закрыть"
                            variant="ghost"
                            size="sm"
                            color="white"
                            _hover={{ bg: 'whiteAlpha.200' }}
                            onClick={onClose}
                        >
                            <LuX size={20} />
                        </IconButton>
                    </HStack>
                </Flex>

                {/* Main content */}
                <Flex flex="1" align="center" justify="center" position="relative" overflow="hidden">
                    {isImage ? (
                        <Box
                            overflow="hidden"
                            w="100%"
                            h="100%"
                            display="flex"
                            alignItems="center"
                            justifyContent="center"
                            p="4"
                        >
                            <img
                                src={currentMedia.original_url}
                                alt={currentMedia.file_name}
                                style={{
                                    transform: `scale(${zoom})`,
                                    maxWidth: '100%',
                                    maxHeight: '100%',
                                    objectFit: 'contain',
                                    transition: 'transform 0.2s ease',
                                }}
                            />
                        </Box>
                    ) : isVideo ? (
                        <Box p="4">
                            <video
                                src={currentMedia.original_url}
                                controls
                                autoPlay
                                style={{
                                    maxWidth: '100%',
                                    maxHeight: 'calc(100vh - 160px)',
                                    borderRadius: '8px',
                                }}
                            />
                        </Box>
                    ) : (
                        <VStack gap="4" color="gray.400">
                            <Box fontSize="6xl">
                                {currentMedia.mime_type?.startsWith('video/') ? <LuVideo /> :
                                    currentMedia.mime_type?.includes('pdf') || currentMedia.mime_type?.startsWith('text/') ? <LuFileText /> :
                                        <LuFile />
                                }
                            </Box>
                            <Text color="white" fontSize="lg">{currentMedia.file_name}</Text>
                            <Text color="gray.400">{currentMedia.mime_type}</Text>
                            <IconButton
                                aria-label="Скачать"
                                onClick={handleDownload}
                                colorPalette="blue"
                                size="lg"
                            >
                                <LuDownload />
                            </IconButton>
                        </VStack>
                    )}

                    {/* Navigation arrows */}
                    {media.length > 1 && (
                        <>
                            <IconButton
                                aria-label="Предыдущий"
                                variant="ghost"
                                position="absolute"
                                left="3"
                                top="50%"
                                transform="translateY(-50%)"
                                size="lg"
                                color="white"
                                bg="blackAlpha.500"
                                _hover={{ bg: 'blackAlpha.700' }}
                                borderRadius="full"
                                onClick={handlePrevious}
                            >
                                <LuChevronLeft size={28} />
                            </IconButton>
                            <IconButton
                                aria-label="Следующий"
                                variant="ghost"
                                position="absolute"
                                right="3"
                                top="50%"
                                transform="translateY(-50%)"
                                size="lg"
                                color="white"
                                bg="blackAlpha.500"
                                _hover={{ bg: 'blackAlpha.700' }}
                                borderRadius="full"
                                onClick={handleNext}
                            >
                                <LuChevronRight size={28} />
                            </IconButton>
                        </>
                    )}
                </Flex>

                {/* Footer controls */}
                <Flex
                    position="relative"
                    zIndex="10"
                    align="center"
                    justify="space-between"
                    px="5"
                    py="3"
                    bg="blackAlpha.700"
                    backdropFilter="blur(8px)"
                >
                    {/* Zoom controls */}
                    <HStack gap="2">
                        {isImage && (
                            <>
                                <IconButton
                                    aria-label="Уменьшить"
                                    variant="ghost"
                                    size="sm"
                                    color="white"
                                    _hover={{ bg: 'whiteAlpha.200' }}
                                    onClick={handleZoomOut}
                                    disabled={zoom <= 0.5}
                                >
                                    <LuZoomOut />
                                </IconButton>
                                <Text color="white" fontSize="sm" minW="50px" textAlign="center">
                                    {Math.round(zoom * 100)}%
                                </Text>
                                <IconButton
                                    aria-label="Увеличить"
                                    variant="ghost"
                                    size="sm"
                                    color="white"
                                    _hover={{ bg: 'whiteAlpha.200' }}
                                    onClick={handleZoomIn}
                                    disabled={zoom >= 3}
                                >
                                    <LuZoomIn />
                                </IconButton>
                            </>
                        )}
                    </HStack>

                    {/* Info badges */}
                    <HStack gap="2" flexWrap="wrap">
                        {currentMedia.collection_name && (
                            <Badge colorPalette="purple" size="sm">{currentMedia.collection_name}</Badge>
                        )}
                        {currentMedia.model_type_label && (
                            <Badge colorPalette="green" size="sm">
                                {currentMedia.model_type_label}
                                {currentMedia.owner_display_name && `: ${currentMedia.owner_display_name}`}
                            </Badge>
                        )}
                    </HStack>

                    {/* Prev / Next buttons */}
                    <HStack gap="1">
                        <IconButton
                            aria-label="Предыдущий"
                            variant="ghost"
                            size="sm"
                            color="white"
                            _hover={{ bg: 'whiteAlpha.200' }}
                            onClick={handlePrevious}
                        >
                            <LuChevronLeft />
                        </IconButton>
                        <IconButton
                            aria-label="Следующий"
                            variant="ghost"
                            size="sm"
                            color="white"
                            _hover={{ bg: 'whiteAlpha.200' }}
                            onClick={handleNext}
                        >
                            <LuChevronRight />
                        </IconButton>
                    </HStack>
                </Flex>
            </Flex>
        </>
    );

    return createPortal(lightboxContent, document.body);
}
