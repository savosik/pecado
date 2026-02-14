import { useState, useEffect, useMemo, useRef } from 'react';
import { Box, IconButton, Spinner } from '@chakra-ui/react';
import { LuChevronLeft, LuChevronRight, LuImageOff } from 'react-icons/lu';

/**
 * Мини-галерея изображений товара.
 * Поддержка ленивой загрузки, автопроигрывания при hover, свайпа на мобильных.
 *
 * @param {{ product: Object, maxImages?: number, showMainImage?: boolean }} props
 */
export default function ProductMiniGallery({
    product,
    maxImages = 6,
    showMainImage = true,
    isHovered = false,
}) {
    const [currentIndex, setCurrentIndex] = useState(0);
    const [loadedImages, setLoadedImages] = useState(new Set());
    const [imageErrors, setImageErrors] = useState(new Set());
    const [touchStart, setTouchStart] = useState(null);
    const [touchEnd, setTouchEnd] = useState(null);
    const [swipeDirection, setSwipeDirection] = useState(null);
    const [isAutoPlaying, setIsAutoPlaying] = useState(false);
    const swipeTimeoutRef = useRef(null);

    const containerRef = useRef(null);
    const observerRef = useRef(null);
    const autoPlayTimerRef = useRef(null);
    const autoPlayIntervalRef = useRef(null);

    // Собираем список изображений
    const images = useMemo(() => {
        const list = [];
        if (showMainImage && (product?.main_image || product?.thumbnail)) {
            list.push({
                url: product.main_image || product.thumbnail,
                thumb: product.thumbnail || product.main_image,
                type: 'main',
            });
        }
        if (product?.gallery_urls && Array.isArray(product.gallery_urls)) {
            product.gallery_urls.forEach((img, index) => {
                list.push({
                    url: img.url,
                    thumb: img.thumb || img.url,
                    type: 'gallery',
                    index,
                });
            });
        }
        return list.slice(0, maxImages);
    }, [product?.id, product?.main_image, product?.thumbnail, maxImages, showMainImage]);

    const hasMultipleImages = images.length > 1;

    // Автопроигрывание
    const startAutoPlay = () => {
        if (!hasMultipleImages || isAutoPlaying) return;
        setIsAutoPlaying(true);
        autoPlayIntervalRef.current = setInterval(() => {
            setCurrentIndex((prev) => {
                const next = (prev + 1) % images.length;
                loadImage(next);
                preloadAdjacent(next);
                return next;
            });
        }, 2000);
    };

    const stopAutoPlay = () => {
        if (autoPlayIntervalRef.current) {
            clearInterval(autoPlayIntervalRef.current);
            autoPlayIntervalRef.current = null;
        }
        setIsAutoPlaying(false);
    };

    const resetAutoPlayTimer = () => {
        if (autoPlayTimerRef.current) clearTimeout(autoPlayTimerRef.current);
        autoPlayTimerRef.current = setTimeout(() => startAutoPlay(), 3000);
    };

    const handleMouseEnter = () => {
        if (hasMultipleImages) resetAutoPlayTimer();
    };

    const handleMouseLeave = () => {
        if (autoPlayTimerRef.current) {
            clearTimeout(autoPlayTimerRef.current);
            autoPlayTimerRef.current = null;
        }
        stopAutoPlay();
    };

    // Очистка таймеров
    useEffect(() => {
        return () => {
            if (autoPlayTimerRef.current) clearTimeout(autoPlayTimerRef.current);
            if (autoPlayIntervalRef.current) clearInterval(autoPlayIntervalRef.current);
            if (swipeTimeoutRef.current) clearTimeout(swipeTimeoutRef.current);
        };
    }, []);

    // Lazy-загрузка первого изображения
    useEffect(() => {
        if (!containerRef.current || images.length === 0) return;

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        if (!loadedImages.has(0)) {
                            const img = new Image();
                            img.onload = () => setLoadedImages((s) => new Set([...s, 0]));
                            img.onerror = () => setImageErrors((s) => new Set([...s, 0]));
                            img.src = images[0].thumb;
                        }
                        observer.disconnect();
                    }
                });
            },
            { threshold: 0.1, rootMargin: '50px' }
        );

        observer.observe(containerRef.current);
        observerRef.current = observer;

        return () => observerRef.current?.disconnect();
    }, [images.length, product?.thumbnail, product?.main_image]);

    const loadImage = (index) => {
        if (loadedImages.has(index) || imageErrors.has(index) || !images[index]) return;
        const img = new Image();
        img.onload = () => setLoadedImages((s) => new Set([...s, index]));
        img.onerror = () => setImageErrors((s) => new Set([...s, index]));
        img.src = images[index].thumb;
    };

    const preloadAdjacent = (idx) => {
        loadImage((idx + 1) % images.length);
        loadImage((idx - 1 + images.length) % images.length);
    };

    // Touch-свайп
    const onTouchStart = (e) => {
        setTouchEnd(null);
        setTouchStart(e.targetTouches[0].clientX);
    };
    const onTouchMove = (e) => setTouchEnd(e.targetTouches[0].clientX);
    const onTouchEnd = () => {
        if (!touchStart || !touchEnd) return;
        const dist = touchStart - touchEnd;

        if (dist > 50) {
            setSwipeDirection('left');
            goNext();
            swipeTimeoutRef.current = setTimeout(() => setSwipeDirection(null), 300);
        }
        if (dist < -50) {
            setSwipeDirection('right');
            goPrev();
            swipeTimeoutRef.current = setTimeout(() => setSwipeDirection(null), 300);
        }
    };

    const goNext = (e) => {
        e?.stopPropagation();
        e?.preventDefault();
        const next = (currentIndex + 1) % images.length;
        setCurrentIndex(next);
        loadImage(next);
        preloadAdjacent(next);
        stopAutoPlay();
    };

    const goPrev = (e) => {
        e?.stopPropagation();
        e?.preventDefault();
        const prev = (currentIndex - 1 + images.length) % images.length;
        setCurrentIndex(prev);
        loadImage(prev);
        preloadAdjacent(prev);
        stopAutoPlay();
    };

    const goToImage = (index, e) => {
        e?.stopPropagation();
        e?.preventDefault();
        setCurrentIndex(index);
        loadImage(index);
        preloadAdjacent(index);
        stopAutoPlay();
    };

    // Заглушка
    if (images.length === 0) {
        return (
            <Box
                w="100%"
                css={{ aspectRatio: '2 / 3' }}
                bg="gray.200"
                _dark={{ bg: 'gray.700' }}
                display="flex"
                alignItems="center"
                justifyContent="center"
                flexDirection="column"
                gap="1"
            >
                <LuImageOff size={20} color="var(--chakra-colors-gray-400)" />
                <Box fontSize="xs" color="gray.400">
                    Нет фото
                </Box>
            </Box>
        );
    }

    const currentImage = images[currentIndex];
    const isCurrentLoaded = loadedImages.has(currentIndex);
    const hasCurrentError = imageErrors.has(currentIndex);

    return (
        <Box
            ref={containerRef}
            position="relative"
            w="100%"
            css={{ aspectRatio: '2 / 3', touchAction: 'pan-y' }}
            overflow="hidden"
            bg="gray.200"
            _dark={{ bg: 'gray.700' }}
            userSelect="none"
            role="group"

            onTouchStart={onTouchStart}
            onTouchMove={onTouchMove}
            onTouchEnd={onTouchEnd}
            onMouseEnter={handleMouseEnter}
            onMouseLeave={handleMouseLeave}
        >
            {/* Спиннер загрузки */}
            {!isCurrentLoaded && !hasCurrentError && (
                <Box position="absolute" inset="0" display="flex" alignItems="center" justifyContent="center">
                    <Spinner size="sm" color="gray.500" />
                </Box>
            )}

            {/* Ошибка */}
            {hasCurrentError ? (
                <Box position="absolute" inset="0" display="flex" alignItems="center" justifyContent="center" flexDirection="column" gap="1">
                    <LuImageOff size={16} color="var(--chakra-colors-gray-400)" />
                    <Box fontSize="xs" color="gray.400">Ошибка</Box>
                </Box>
            ) : (
                <Box
                    as="img"
                    src={currentImage.thumb}
                    alt={`${product?.name || 'Товар'}`}
                    position="absolute"
                    inset="0"
                    display="block"
                    h="100%"
                    w="100%"
                    objectFit="cover"
                    transition="opacity 0.3s, transform 0.3s"
                    opacity={isCurrentLoaded ? 1 : 0}
                    transform={
                        swipeDirection === 'left' ? 'translateX(-8px)' :
                            swipeDirection === 'right' ? 'translateX(8px)' :
                                'translateX(0)'
                    }
                    onLoad={() => setLoadedImages((s) => new Set([...s, currentIndex]))}
                    onError={() => setImageErrors((s) => new Set([...s, currentIndex]))}
                />
            )}

            {/* Dot-индикаторы */}
            {hasMultipleImages && (
                <Box
                    position="absolute"
                    bottom="2"
                    left="50%"
                    transform="translateX(-50%)"
                    display="flex"
                    gap="1.5"
                    opacity={isHovered ? 1 : 0}
                    transition="opacity 0.2s"
                    zIndex="2"
                >
                    {images.map((_, i) => (
                        <Box
                            key={i}
                            as="button"
                            w="8px"
                            h="8px"
                            borderRadius="full"
                            bg={i === currentIndex ? 'white' : 'whiteAlpha.600'}
                            boxShadow={i === currentIndex ? 'sm' : 'none'}
                            transition="all 0.2s"
                            _hover={{ bg: 'whiteAlpha.800' }}
                            onClick={(e) => goToImage(i, e)}
                            aria-label={`Изображение ${i + 1}`}
                        />
                    ))}
                </Box>
            )}

            {/* Кнопки навигации */}
            {hasMultipleImages && (
                <>
                    <IconButton
                        aria-label="Предыдущее"
                        position="absolute"
                        left="1"
                        top="50%"
                        transform="translateY(-50%)"
                        size="xs"
                        variant="solid"
                        bg="blackAlpha.500"
                        color="white"
                        borderRadius="md"
                        opacity={isHovered ? 1 : 0}
                        transition="opacity 0.2s"
                        _hover={{ bg: 'blackAlpha.700' }}
                        onClick={goPrev}
                        minW="28px"
                        h="28px"
                        zIndex="2"
                    >
                        <LuChevronLeft size={16} />
                    </IconButton>
                    <IconButton
                        aria-label="Следующее"
                        position="absolute"
                        right="1"
                        top="50%"
                        transform="translateY(-50%)"
                        size="xs"
                        variant="solid"
                        bg="blackAlpha.500"
                        color="white"
                        borderRadius="md"
                        opacity={isHovered ? 1 : 0}
                        transition="opacity 0.2s"
                        _hover={{ bg: 'blackAlpha.700' }}
                        onClick={goNext}
                        minW="28px"
                        h="28px"
                        zIndex="2"
                    >
                        <LuChevronRight size={16} />
                    </IconButton>
                </>
            )}
        </Box>
    );
}
