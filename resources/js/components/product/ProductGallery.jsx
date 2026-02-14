import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Box, Flex, IconButton, Text } from '@chakra-ui/react';
import { LuChevronLeft, LuChevronRight, LuZoomIn, LuPlay, LuX } from 'react-icons/lu';

/**
 * ProductGallery — галерея изображений/видео товара
 * с настоящим drag-свайпом (как в BannerSlider), стрелками и лайтбоксом.
 *
 * @param {{ media: Array<{url: string, type: 'image'|'video'}>, productName: string }} props
 */
export default function ProductGallery({ media = [], productName = '' }) {
    const [currentIndex, setCurrentIndex] = useState(() => {
        const firstImage = Array.isArray(media) ? media.findIndex(m => m?.type === 'image') : -1;
        return firstImage >= 0 ? firstImage : 0;
    });
    const [isLightboxOpen, setIsLightboxOpen] = useState(false);

    // ─── Drag-свайп state ───────────────────────────────────────
    const [dragOffset, setDragOffset] = useState(0);
    const [isTransitioning, setIsTransitioning] = useState(false);
    const [slideDirection, setSlideDirection] = useState(1);

    const containerRef = useRef(null);
    const dragStartXRef = useRef(null);
    const isDraggingRef = useRef(false);
    const prevIndexRef = useRef(0);
    const thumbRefs = useRef([]);
    const thumbContainerRef = useRef(null);
    const [canScroll, setCanScroll] = useState({ left: false, right: false });
    const [hoveringMain, setHoveringMain] = useState(false);
    const [hoveringThumbs, setHoveringThumbs] = useState(false);

    const hasMultiple = useMemo(() => media.length > 1, [media.length]);
    const total = media.length;

    // ─── Navigation ─────────────────────────────────────────────
    const goTo = useCallback((idx, direction) => {
        if (total <= 1) return;
        const newIndex = ((idx % total) + total) % total;
        prevIndexRef.current = currentIndex;
        setSlideDirection(direction ?? (newIndex > currentIndex ? 1 : -1));
        setIsTransitioning(true);
        setCurrentIndex(newIndex);
        setDragOffset(0);
    }, [total, currentIndex]);

    const nextMedia = useCallback(() => goTo(currentIndex + 1, 1), [goTo, currentIndex]);
    const prevMedia = useCallback(() => goTo(currentIndex - 1, -1), [goTo, currentIndex]);

    // ─── Drag handlers ──────────────────────────────────────────
    const onDragStart = useCallback((clientX) => {
        dragStartXRef.current = clientX;
        isDraggingRef.current = false;
        setIsTransitioning(false);
    }, []);

    const onDragMove = useCallback((clientX) => {
        if (dragStartXRef.current === null) return;
        const dx = clientX - dragStartXRef.current;
        if (Math.abs(dx) > 5) isDraggingRef.current = true;
        setDragOffset(dx);
    }, []);

    const onDragEnd = useCallback(() => {
        const startX = dragStartXRef.current;
        dragStartXRef.current = null;
        if (startX === null) {
            setDragOffset(0);
            return;
        }

        const containerWidth = containerRef.current?.clientWidth || 1;
        const threshold = containerWidth * 0.15;

        setIsTransitioning(true);

        if (dragOffset < -threshold) {
            prevIndexRef.current = currentIndex;
            setSlideDirection(1);
            setCurrentIndex((i) => (i + 1) % total);
        } else if (dragOffset > threshold) {
            prevIndexRef.current = currentIndex;
            setSlideDirection(-1);
            setCurrentIndex((i) => (i - 1 + total) % total);
        }

        setDragOffset(0);

        if (isDraggingRef.current) {
            setTimeout(() => { isDraggingRef.current = false; }, 0);
        }
    }, [dragOffset, total, currentIndex]);

    // Mouse handlers
    const handleMouseDown = useCallback((e) => {
        if (e.button !== 0) return;
        e.preventDefault();
        onDragStart(e.clientX);
    }, [onDragStart]);

    const handleMouseMove = useCallback((e) => {
        onDragMove(e.clientX);
    }, [onDragMove]);

    const handleMouseUp = useCallback(() => {
        onDragEnd();
    }, [onDragEnd]);

    // Touch handlers
    const handleTouchStart = useCallback((e) => {
        onDragStart(e.touches[0].clientX);
    }, [onDragStart]);

    const handleTouchMove = useCallback((e) => {
        onDragMove(e.touches[0].clientX);
    }, [onDragMove]);

    const handleTouchEnd = useCallback(() => {
        onDragEnd();
    }, [onDragEnd]);

    const handleClick = useCallback((e) => {
        if (isDraggingRef.current) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, []);

    // Global mouse events for drag
    useEffect(() => {
        const onMove = (e) => handleMouseMove(e);
        const onUp = () => handleMouseUp();
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        return () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        };
    }, [handleMouseMove, handleMouseUp]);

    // Навигация клавиатурой в лайтбоксе
    useEffect(() => {
        if (!isLightboxOpen) return;
        const handleKey = (e) => {
            if (e.key === 'Escape') setIsLightboxOpen(false);
            else if (e.key === 'ArrowRight') nextMedia();
            else if (e.key === 'ArrowLeft') prevMedia();
        };
        document.addEventListener('keydown', handleKey);
        return () => document.removeEventListener('keydown', handleKey);
    }, [isLightboxOpen, nextMedia, prevMedia]);

    // Авто-скролл миниатюры в поле зрения
    useEffect(() => {
        const el = thumbRefs.current[currentIndex];
        if (!el) return;
        try { el.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' }); } catch { }
    }, [currentIndex, isLightboxOpen]);

    // ─── Thumbnail overflow detection ───────────────────────────
    const updateCanScroll = useCallback(() => {
        const el = thumbContainerRef.current;
        if (!el) return;
        setCanScroll({
            left: el.scrollLeft > 2,
            right: el.scrollLeft + el.clientWidth < el.scrollWidth - 2,
        });
    }, []);

    useEffect(() => {
        const el = thumbContainerRef.current;
        if (!el) return;
        updateCanScroll();
        el.addEventListener('scroll', updateCanScroll, { passive: true });
        const ro = new ResizeObserver(updateCanScroll);
        ro.observe(el);
        return () => {
            el.removeEventListener('scroll', updateCanScroll);
            ro.disconnect();
        };
    }, [updateCanScroll, media.length]);

    const scrollThumbs = (direction) => {
        const el = thumbContainerRef.current;
        if (!el) return;
        el.scrollBy({ left: direction * 200, behavior: 'smooth' });
    };

    // ─── Slide position helper ──────────────────────────────────
    const getSlidePosition = (i) => {
        if (i === currentIndex) return 0;

        const nextIdx = (currentIndex + 1) % total;
        const prevIdx = (currentIndex - 1 + total) % total;

        if (dragOffset < 0 && i === nextIdx) return 1;
        if (dragOffset > 0 && i === prevIdx) return -1;
        if (dragOffset === 0) {
            if (i === prevIndexRef.current && isTransitioning) {
                return slideDirection === 1 ? -1 : 1;
            }
            if (i === nextIdx && slideDirection === 1) return 1;
            if (i === prevIdx && slideDirection === -1) return -1;
        }

        return null;
    };

    // ─── Пустая галерея ─────────────────────────────────────────
    if (!media || media.length === 0) {
        return (
            <Box maxW={{ base: '300px', md: '100%' }} mx="auto">
                <Box
                    css={{ aspectRatio: '2 / 3' }}
                    bg="gray.50"
                    _dark={{ bg: 'gray.800' }}
                    rounded="sm"
                    overflow="hidden"
                    display="flex"
                    alignItems="center"
                    justifyContent="center"
                    color="gray.400"
                    fontSize="sm"
                >
                    Нет изображений
                </Box>
            </Box>
        );
    }

    return (
        <>
            <Box spaceY={{ base: '2', md: '3' }} maxW={{ base: '300px', md: '100%' }} mx="auto">
                {/* ═══ Основное изображение — drag-свайпер ═══ */}
                <Box
                    ref={containerRef}
                    position="relative"
                    overflow="hidden"
                    rounded="sm"
                    css={{ aspectRatio: '2 / 3' }}
                    bg="gray.50"
                    _dark={{ bg: 'gray.800' }}
                    cursor={hasMultiple ? 'grab' : undefined}
                    userSelect="none"
                    onMouseDown={hasMultiple ? handleMouseDown : undefined}
                    onMouseEnter={() => setHoveringMain(true)}
                    onMouseLeave={() => setHoveringMain(false)}
                    onTouchStart={hasMultiple ? handleTouchStart : undefined}
                    onTouchMove={hasMultiple ? handleTouchMove : undefined}
                    onTouchEnd={hasMultiple ? handleTouchEnd : undefined}
                    onClick={(e) => {
                        handleClick(e);
                        if (!isDraggingRef.current) setIsLightboxOpen(true);
                    }}
                    onDragStart={(e) => e.preventDefault()}
                >
                    {/* Слайды */}
                    {media.map((item, i) => {
                        if (!hasMultiple) {
                            if (i !== currentIndex) return null;
                        } else {
                            const position = getSlidePosition(i);
                            if (position === null) return null;

                            const translateX = `calc(${position * 100}% + ${dragOffset}px)`;
                            return (
                                <Box
                                    key={i}
                                    position={i === currentIndex && !hasMultiple ? 'relative' : 'absolute'}
                                    inset="0"
                                    transform={`translateX(${translateX})`}
                                    transition={isTransitioning ? 'transform 0.4s ease' : 'none'}
                                    willChange="transform"
                                >
                                    {item.type === 'video' ? (
                                        <Box
                                            as="video"
                                            src={item.url}
                                            w="100%" h="100%"
                                            objectFit="cover"
                                            controls
                                            onClick={(e) => e.stopPropagation()}
                                            draggable="false"
                                        />
                                    ) : (
                                        <Box
                                            as="img"
                                            src={item.url}
                                            alt={`${productName} ${i + 1}`}
                                            w="100%" h="100%"
                                            objectFit="cover"
                                            draggable="false"
                                            loading={i === currentIndex ? 'eager' : 'lazy'}
                                        />
                                    )}
                                </Box>
                            );
                        }

                        // Single image (no swipe)
                        return (
                            <Box key={i} w="100%" h="100%">
                                {item.type === 'video' ? (
                                    <Box
                                        as="video"
                                        src={item.url}
                                        w="100%" h="100%"
                                        objectFit="cover"
                                        controls
                                        onClick={(e) => e.stopPropagation()}
                                    />
                                ) : (
                                    <Box
                                        as="img"
                                        src={item.url}
                                        alt={productName}
                                        w="100%" h="100%"
                                        objectFit="cover"
                                    />
                                )}
                            </Box>
                        );
                    })}

                    {/* ═══ Стрелки навигации (при наведении) ═══ */}
                    {hasMultiple && (
                        <>
                            <IconButton
                                aria-label="Предыдущее"
                                position="absolute" left="3" top="50%" transform="translateY(-50%)"
                                size="sm" variant="solid"
                                bg="white/80" color="gray.800"
                                _hover={{ bg: 'white' }}
                                _dark={{ bg: 'gray.700/80', color: 'white', _hover: { bg: 'gray.700' } }}
                                borderRadius="full" shadow="md" zIndex="2"
                                opacity={hoveringMain ? 1 : 0} transition="opacity 0.2s"
                                onClick={(e) => { e.stopPropagation(); prevMedia(); }}
                            >
                                <LuChevronLeft />
                            </IconButton>
                            <IconButton
                                aria-label="Следующее"
                                position="absolute" right="3" top="50%" transform="translateY(-50%)"
                                size="sm" variant="solid"
                                bg="white/80" color="gray.800"
                                _hover={{ bg: 'white' }}
                                _dark={{ bg: 'gray.700/80', color: 'white', _hover: { bg: 'gray.700' } }}
                                borderRadius="full" shadow="md" zIndex="2"
                                opacity={hoveringMain ? 1 : 0} transition="opacity 0.2s"
                                onClick={(e) => { e.stopPropagation(); nextMedia(); }}
                            >
                                <LuChevronRight />
                            </IconButton>
                        </>
                    )}

                    {/* Кнопка увеличения */}
                    <IconButton
                        aria-label="Увеличить"
                        position="absolute" bottom="3" right="3"
                        bg="blackAlpha.600" _hover={{ bg: 'blackAlpha.700' }}
                        color="white" rounded="sm" size="sm" zIndex="2"
                        opacity={hoveringMain ? 1 : 0} transition="opacity 0.2s"
                        onClick={(e) => { e.stopPropagation(); setIsLightboxOpen(true); }}
                    >
                        {media[currentIndex]?.type === 'video' ? <LuPlay /> : <LuZoomIn />}
                    </IconButton>
                </Box>

                {/* ═══ Миниатюры со стрелками при наведении ═══ */}
                {hasMultiple && (
                    <Box
                        position="relative"
                        onMouseEnter={() => setHoveringThumbs(true)}
                        onMouseLeave={() => setHoveringThumbs(false)}
                    >
                        {/* Стрелка влево */}
                        {canScroll.left && (
                            <IconButton
                                aria-label="Прокрутить влево"
                                position="absolute" left="-1" top="50%" transform="translateY(-50%)"
                                zIndex="2" size="sm" variant="solid"
                                bg="white/80" color="gray.800"
                                _hover={{ bg: 'white' }}
                                _dark={{ bg: 'gray.700/80', color: 'white', _hover: { bg: 'gray.700' } }}
                                borderRadius="full" shadow="md"
                                opacity={hoveringThumbs ? 1 : 0} transition="opacity 0.2s"
                                onClick={() => scrollThumbs(-1)}
                            >
                                <LuChevronLeft />
                            </IconButton>
                        )}

                        <Flex
                            ref={thumbContainerRef}
                            gap={{ base: '1', md: '2' }}
                            overflowX="auto"
                            pb={{ base: '1', md: '2' }}
                            px="1"
                            css={{ scrollbarWidth: 'none', '&::-webkit-scrollbar': { display: 'none' } }}
                        >
                            {media.map((item, index) => (
                                <Box
                                    as="button"
                                    key={index}
                                    ref={el => { thumbRefs.current[index] = el; }}
                                    onClick={() => goTo(index)}
                                    flexShrink={0}
                                    w={{ base: '14', md: '16' }}
                                    css={{ aspectRatio: '2 / 3' }}
                                    rounded="sm"
                                    overflow="hidden"
                                    borderWidth="2px"
                                    borderColor={index === currentIndex ? 'pink.500' : 'gray.200'}
                                    _dark={{ borderColor: index === currentIndex ? 'pink.400' : 'gray.600' }}
                                    _hover={{ borderColor: index === currentIndex ? undefined : 'gray.300' }}
                                    transition="border-color 0.15s"
                                    position="relative"
                                >
                                    {item.type === 'video' ? (
                                        <Box position="relative" w="100%" h="100%">
                                            <Box as="video" src={item.url} w="100%" h="100%" objectFit="cover" muted preload="metadata" />
                                            <Flex position="absolute" inset="0" align="center" justify="center" bg="blackAlpha.300">
                                                <LuPlay size={14} color="white" />
                                            </Flex>
                                        </Box>
                                    ) : (
                                        <Box as="img" src={item.url} alt={`${productName} ${index + 1}`} w="100%" h="100%" objectFit="cover" loading="lazy" />
                                    )}
                                </Box>
                            ))}
                        </Flex>

                        {/* Стрелка вправо */}
                        {canScroll.right && (
                            <IconButton
                                aria-label="Прокрутить вправо"
                                position="absolute" right="-1" top="50%" transform="translateY(-50%)"
                                zIndex="2" size="sm" variant="solid"
                                bg="white/80" color="gray.800"
                                _hover={{ bg: 'white' }}
                                _dark={{ bg: 'gray.700/80', color: 'white', _hover: { bg: 'gray.700' } }}
                                borderRadius="full" shadow="md"
                                opacity={hoveringThumbs ? 1 : 0} transition="opacity 0.2s"
                                onClick={() => scrollThumbs(1)}
                            >
                                <LuChevronRight />
                            </IconButton>
                        )}
                    </Box>
                )}
            </Box>

            {/* ═══ Лайтбокс ═══ */}
            {isLightboxOpen && (
                <Box
                    position="fixed" inset="0" zIndex="50"
                    bg="blackAlpha.900"
                    display="flex" flexDirection="column"
                >
                    {/* Шапка */}
                    <Flex align="center" justify="space-between" p={{ base: '3', md: '4' }} position="relative">
                        <Box
                            position="absolute" inset="0"
                            bgGradient="to-b" gradientFrom="blackAlpha.600" gradientTo="transparent"
                            pointerEvents="none"
                        />
                        <Flex position="relative" align="center" gap="2" color="white" fontSize={{ base: 'sm', md: 'md' }} pr="4" minW="0">
                            <Text truncate>{productName}</Text>
                            {hasMultiple && (
                                <Text color="whiteAlpha.700" flexShrink={0}>{currentIndex + 1}/{media.length}</Text>
                            )}
                        </Flex>
                        <IconButton
                            aria-label="Закрыть"
                            position="relative"
                            variant="ghost" color="white" size="sm"
                            _hover={{ bg: 'whiteAlpha.200' }}
                            onClick={() => setIsLightboxOpen(false)}
                        >
                            <LuX />
                        </IconButton>
                    </Flex>

                    {/* Контент */}
                    <Flex
                        flex="1" align="center" justify="center" px={{ base: '3', md: '6' }} position="relative"
                        onTouchStart={handleTouchStart}
                        onTouchMove={handleTouchMove}
                        onTouchEnd={handleTouchEnd}
                    >
                        <Box
                            maxW="95vw" maxH="80vh"
                            bg="black" rounded="sm" overflow="hidden"
                            display="flex" alignItems="center" justifyContent="center"
                        >
                            {media[currentIndex]?.type === 'video' ? (
                                <Box as="video" src={media[currentIndex].url} maxW="95vw" maxH="80vh" objectFit="contain" controls autoPlay />
                            ) : (
                                <Box as="img" src={media[currentIndex]?.url} alt={productName} maxW="95vw" maxH="80vh" objectFit="contain" />
                            )}
                        </Box>

                        {hasMultiple && (
                            <>
                                <IconButton
                                    aria-label="Предыдущее"
                                    position="absolute" left={{ base: '3', md: '6' }} top="50%" transform="translateY(-50%)"
                                    bg="whiteAlpha.200" _hover={{ bg: 'whiteAlpha.300' }}
                                    color="white" rounded="sm" size="md"
                                    onClick={prevMedia}
                                >
                                    <LuChevronLeft />
                                </IconButton>
                                <IconButton
                                    aria-label="Следующее"
                                    position="absolute" right={{ base: '3', md: '6' }} top="50%" transform="translateY(-50%)"
                                    bg="whiteAlpha.200" _hover={{ bg: 'whiteAlpha.300' }}
                                    color="white" rounded="sm" size="md"
                                    onClick={nextMedia}
                                >
                                    <LuChevronRight />
                                </IconButton>
                            </>
                        )}
                    </Flex>

                    {/* Миниатюры в лайтбоксе */}
                    {hasMultiple && (
                        <Box p={{ base: '3', md: '4' }} borderTopWidth="1px" borderColor="whiteAlpha.200">
                            <Flex
                                gap="2" overflowX="auto" pb="1"
                                css={{ scrollbarWidth: 'none', '&::-webkit-scrollbar': { display: 'none' } }}
                            >
                                {media.map((item, index) => (
                                    <Box
                                        as="button"
                                        key={index}
                                        ref={el => { thumbRefs.current[index] = el; }}
                                        onClick={() => goTo(index)}
                                        flexShrink={0}
                                        w={{ base: '16', md: '20' }}
                                        css={{ aspectRatio: '2 / 3' }}
                                        rounded="sm"
                                        overflow="hidden"
                                        borderWidth="2px"
                                        borderColor={index === currentIndex ? 'white' : 'whiteAlpha.400'}
                                        _hover={{ borderColor: index === currentIndex ? undefined : 'whiteAlpha.700' }}
                                        transition="border-color 0.15s"
                                        position="relative"
                                    >
                                        {item.type === 'video' ? (
                                            <Box position="relative" w="100%" h="100%">
                                                <Box as="video" src={item.url} w="100%" h="100%" objectFit="cover" muted preload="metadata" />
                                                <Flex position="absolute" inset="0" align="center" justify="center" bg="blackAlpha.400">
                                                    <LuPlay size={14} color="white" />
                                                </Flex>
                                            </Box>
                                        ) : (
                                            <Box as="img" src={item.url} alt={`${productName} ${index + 1}`} w="100%" h="100%" objectFit="cover" loading="lazy" />
                                        )}
                                    </Box>
                                ))}
                            </Flex>
                        </Box>
                    )}
                </Box>
            )}
        </>
    );
}
