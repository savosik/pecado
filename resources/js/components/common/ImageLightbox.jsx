import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Box, Flex, IconButton, Text } from '@chakra-ui/react';
import { LuChevronLeft, LuChevronRight, LuX } from 'react-icons/lu';

/**
 * Полноэкранный просмотрщик изображений — тот же визуал, что у лайтбокса
 * основной галереи товара (тёмный фон, шапка с названием и счётчиком, кнопка
 * закрытия, стрелки, свайп на тач). Переиспользуется там, где нужно увеличить
 * картинку по клику единообразно с основным фото товара.
 *
 * @param {{
 *   images: Array<{ url: string, alt?: string }>,
 *   initialIndex?: number,
 *   open: boolean,
 *   onClose: () => void,
 *   title?: string,
 * }} props
 */
export default function ImageLightbox({ images = [], initialIndex = 0, open, onClose, title = '' }) {
    const [index, setIndex] = useState(initialIndex);
    const touchStartX = useRef(null);

    const hasMultiple = images.length > 1;

    useEffect(() => {
        if (open) setIndex(initialIndex);
    }, [open, initialIndex]);

    const prev = useCallback(() => {
        setIndex((i) => (i > 0 ? i - 1 : images.length - 1));
    }, [images.length]);

    const next = useCallback(() => {
        setIndex((i) => (i < images.length - 1 ? i + 1 : 0));
    }, [images.length]);

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => {
            if (e.key === 'Escape') onClose();
            else if (e.key === 'ArrowLeft') prev();
            else if (e.key === 'ArrowRight') next();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose, prev, next]);

    if (!open || images.length === 0) {
        return null;
    }

    const current = images[index] || images[0];

    const handleTouchStart = (e) => { touchStartX.current = e.touches[0].clientX; };
    const handleTouchEnd = (e) => {
        if (touchStartX.current === null) return;
        const dx = e.changedTouches[0].clientX - touchStartX.current;
        if (Math.abs(dx) > 50) (dx > 0 ? prev : next)();
        touchStartX.current = null;
    };

    return createPortal(
        <Box
            position="fixed" inset="0" zIndex="2000"
            bg="blackAlpha.900"
            display="flex" flexDirection="column"
            onClick={onClose}
        >
            {/* Шапка */}
            <Flex align="center" justify="space-between" p={{ base: '3', md: '4' }} position="relative" onClick={(e) => e.stopPropagation()}>
                <Box position="absolute" inset="0" bgGradient="to-b" gradientFrom="blackAlpha.600" gradientTo="transparent" pointerEvents="none" />
                <Flex position="relative" align="center" gap="2" color="white" fontSize={{ base: 'sm', md: 'md' }} pr="4" minW="0">
                    {title && <Text truncate>{title}</Text>}
                    {hasMultiple && (
                        <Text color="whiteAlpha.700" flexShrink={0}>{index + 1}/{images.length}</Text>
                    )}
                </Flex>
                <IconButton
                    aria-label="Закрыть"
                    position="relative"
                    variant="ghost" color="white" size="sm"
                    _hover={{ bg: 'whiteAlpha.200' }}
                    onClick={onClose}
                >
                    <LuX />
                </IconButton>
            </Flex>

            {/* Контент */}
            <Flex
                flex="1" align="center" justify="center" px={{ base: '3', md: '6' }} position="relative"
                onClick={(e) => e.stopPropagation()}
                onTouchStart={handleTouchStart}
                onTouchEnd={handleTouchEnd}
            >
                <Box maxW="95vw" maxH="80vh" bg="black" rounded="sm" overflow="hidden" display="flex" alignItems="center" justifyContent="center">
                    <Box as="img" src={current.url} alt={current.alt || title} maxW="95vw" maxH="80vh" objectFit="contain" decoding="async" />
                </Box>

                {hasMultiple && (
                    <>
                        <IconButton
                            aria-label="Предыдущее"
                            position="absolute" left={{ base: '3', md: '6' }} top="50%" transform="translateY(-50%)"
                            bg="whiteAlpha.200" _hover={{ bg: 'whiteAlpha.300' }}
                            color="white" rounded="sm" size="md"
                            onClick={prev}
                        >
                            <LuChevronLeft />
                        </IconButton>
                        <IconButton
                            aria-label="Следующее"
                            position="absolute" right={{ base: '3', md: '6' }} top="50%" transform="translateY(-50%)"
                            bg="whiteAlpha.200" _hover={{ bg: 'whiteAlpha.300' }}
                            color="white" rounded="sm" size="md"
                            onClick={next}
                        >
                            <LuChevronRight />
                        </IconButton>
                    </>
                )}
            </Flex>
        </Box>,
        document.body,
    );
}
