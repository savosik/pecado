import { useEffect, useRef, useState, useCallback } from 'react';
import { Box, IconButton, Flex } from '@chakra-ui/react';
import { LuChevronLeft, LuChevronRight } from 'react-icons/lu';

/**
 * BannerSlider — карусель баннеров с поддержкой drag-свайпа.
 * Слайды сдвигаются за мышью/пальцем при перетаскивании.
 *
 * @param {Object} props
 * @param {Array} props.banners — [{id, title, desktop_image, mobile_image, link_url}]
 * @param {number} [props.autoPlayMs=5000] — интервал автопрокрутки (мс), 0 = выкл
 */
export default function BannerSlider({ banners = [], autoPlayMs = 5000 }) {
    const [activeIndex, setActiveIndex] = useState(0);
    const [dragOffset, setDragOffset] = useState(0);
    const [isTransitioning, setIsTransitioning] = useState(false);
    // Направление последнего перехода: 1 = вправо (next), -1 = влево (prev)
    const [slideDirection, setSlideDirection] = useState(1);

    const timerRef = useRef(null);
    const containerRef = useRef(null);
    const dragStartXRef = useRef(null);
    const isDraggingRef = useRef(false);
    const prevIndexRef = useRef(0);

    const hasSlides = banners.length > 0;
    const total = banners.length;

    // ─── Navigation ────────────────────────────────────────────
    const goTo = useCallback((idx, direction) => {
        if (!hasSlides) return;
        const newIndex = ((idx % total) + total) % total;
        prevIndexRef.current = activeIndex;
        setSlideDirection(direction ?? (newIndex > activeIndex ? 1 : -1));
        setIsTransitioning(true);
        setActiveIndex(newIndex);
        setDragOffset(0);
    }, [hasSlides, total, activeIndex]);

    const next = useCallback(() => goTo(activeIndex + 1, 1), [goTo, activeIndex]);
    const prev = useCallback(() => goTo(activeIndex - 1, -1), [goTo, activeIndex]);

    // ─── Auto-play ─────────────────────────────────────────────
    const startTimer = useCallback(() => {
        if (!hasSlides || autoPlayMs <= 0 || total <= 1) return;
        clearInterval(timerRef.current);
        timerRef.current = setInterval(() => {
            setActiveIndex((current) => {
                prevIndexRef.current = current;
                setSlideDirection(1);
                setIsTransitioning(true);
                setDragOffset(0);
                return (current + 1) % total;
            });
        }, autoPlayMs);
    }, [hasSlides, autoPlayMs, total]);

    const stopTimer = useCallback(() => {
        clearInterval(timerRef.current);
    }, []);

    useEffect(() => {
        startTimer();
        return stopTimer;
    }, [startTimer, stopTimer]);

    // ─── Drag start (mouse + touch) ────────────────────────────
    const onDragStart = useCallback((clientX) => {
        dragStartXRef.current = clientX;
        isDraggingRef.current = false;
        setIsTransitioning(false);
    }, []);

    // ─── Drag move ─────────────────────────────────────────────
    const onDragMove = useCallback((clientX) => {
        if (dragStartXRef.current === null) return;
        const dx = clientX - dragStartXRef.current;
        if (Math.abs(dx) > 5) isDraggingRef.current = true;
        setDragOffset(dx);
    }, []);

    // ─── Drag end ──────────────────────────────────────────────
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
            // Свайп влево → следующий слайд
            prevIndexRef.current = activeIndex;
            setSlideDirection(1);
            setActiveIndex((i) => (i + 1) % total);
        } else if (dragOffset > threshold) {
            // Свайп вправо → предыдущий слайд
            prevIndexRef.current = activeIndex;
            setSlideDirection(-1);
            setActiveIndex((i) => (i - 1 + total) % total);
        }

        setDragOffset(0);

        if (isDraggingRef.current) {
            setTimeout(() => { isDraggingRef.current = false; }, 0);
        }
    }, [dragOffset, total, activeIndex]);

    // ─── Mouse handlers ────────────────────────────────────────
    const handleMouseDown = useCallback((e) => {
        e.preventDefault();
        onDragStart(e.clientX);
    }, [onDragStart]);

    const handleMouseMove = useCallback((e) => {
        onDragMove(e.clientX);
    }, [onDragMove]);

    const handleMouseUp = useCallback(() => {
        onDragEnd();
    }, [onDragEnd]);

    // ─── Touch handlers ────────────────────────────────────────
    const handleTouchStart = useCallback((e) => {
        onDragStart(e.touches[0].clientX);
    }, [onDragStart]);

    const handleTouchMove = useCallback((e) => {
        onDragMove(e.touches[0].clientX);
    }, [onDragMove]);

    const handleTouchEnd = useCallback(() => {
        onDragEnd();
    }, [onDragEnd]);

    // Блокируем переход по ссылке при drag
    const handleClick = useCallback((e) => {
        if (isDraggingRef.current) {
            e.preventDefault();
        }
    }, []);

    // Global mouse events
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

    // ─── Empty state ───────────────────────────────────────────
    if (!hasSlides) return null;

    /**
     * Вычисляем позицию слайда. Показываем только текущий + один соседний
     * в направлении, куда тянем (или в направлении последнего перехода).
     */
    const getSlidePosition = (i) => {
        if (i === activeIndex) return 0; // текущий слайд — по центру

        // Определяем какой соседний слайд показать:
        // - при drag влево (dragOffset < 0) → показываем следующий (справа)
        // - при drag вправо (dragOffset > 0) → показываем предыдущий (слева)
        // - без drag → показываем соседний по направлению последнего перехода
        const nextIdx = (activeIndex + 1) % total;
        const prevIdx = (activeIndex - 1 + total) % total;

        if (dragOffset < 0 && i === nextIdx) return 1;   // справа
        if (dragOffset > 0 && i === prevIdx) return -1;  // слева
        if (dragOffset === 0) {
            // Во время анимации перехода показываем предыдущий слайд
            if (i === prevIndexRef.current && isTransitioning) {
                return slideDirection === 1 ? -1 : 1;
            }
            // По умолчанию показываем соседей по направлению
            if (i === nextIdx && slideDirection === 1) return 1;
            if (i === prevIdx && slideDirection === -1) return -1;
        }

        return null; // не показывать
    };

    return (
        <Box
            ref={containerRef}
            position="relative"
            overflow="hidden"
            borderRadius="xl"
            mb="8"
            cursor="grab"
            userSelect="none"
            onMouseEnter={stopTimer}
            onMouseLeave={startTimer}
            onMouseDown={handleMouseDown}
            onClick={handleClick}
            onDragStart={(e) => e.preventDefault()}
            onTouchStart={handleTouchStart}
            onTouchMove={handleTouchMove}
            onTouchEnd={handleTouchEnd}
        >
            {/* Slides */}
            <Box
                position="relative"
                h={{ base: '200px', sm: '280px', md: '380px', lg: '460px' }}
            >
                {banners.map((slide, i) => {
                    const position = getSlidePosition(i);
                    if (position === null) return null;

                    const Wrapper = slide.link_url ? 'a' : 'div';
                    const wrapperProps = slide.link_url
                        ? { href: slide.link_url, 'aria-label': slide.title || `Баннер ${i + 1}` }
                        : {};

                    const translateX = `calc(${position * 100}% + ${dragOffset}px)`;

                    return (
                        <Box
                            as={Wrapper}
                            {...wrapperProps}
                            key={slide.id ?? i}
                            position="absolute"
                            inset="0"
                            display="block"
                            transform={`translateX(${translateX})`}
                            transition={isTransitioning ? 'transform 0.4s ease' : 'none'}
                            willChange="transform"
                        >
                            <picture>
                                {slide.mobile_image && (
                                    <source
                                        media="(max-width: 640px)"
                                        srcSet={slide.mobile_image}
                                    />
                                )}
                                <Box
                                    as="img"
                                    src={slide.desktop_image}
                                    alt={slide.title || 'Баннер'}
                                    w="100%"
                                    h="100%"
                                    objectFit="cover"
                                    loading={i === 0 ? 'eager' : 'lazy'}
                                    draggable="false"
                                />
                            </picture>
                        </Box>
                    );
                })}
            </Box>

            {/* Arrows */}
            {total > 1 && (
                <>
                    <IconButton
                        aria-label="Предыдущий слайд"
                        onClick={prev}
                        position="absolute"
                        left="3"
                        top="50%"
                        transform="translateY(-50%)"
                        size="sm"
                        variant="solid"
                        bg="white/80"
                        color="gray.800"
                        _hover={{ bg: 'white' }}
                        borderRadius="full"
                        shadow="md"
                        zIndex="2"
                    >
                        <LuChevronLeft />
                    </IconButton>
                    <IconButton
                        aria-label="Следующий слайд"
                        onClick={next}
                        position="absolute"
                        right="3"
                        top="50%"
                        transform="translateY(-50%)"
                        size="sm"
                        variant="solid"
                        bg="white/80"
                        color="gray.800"
                        _hover={{ bg: 'white' }}
                        borderRadius="full"
                        shadow="md"
                        zIndex="2"
                    >
                        <LuChevronRight />
                    </IconButton>
                </>
            )}

            {/* Dots */}
            {total > 1 && (
                <Flex
                    position="absolute"
                    bottom="3"
                    left="0"
                    right="0"
                    justify="center"
                    gap="2"
                    zIndex="2"
                >
                    {banners.map((_, i) => (
                        <Box
                            as="button"
                            key={i}
                            onClick={() => goTo(i)}
                            w="10px"
                            h="10px"
                            borderRadius="full"
                            bg={i === activeIndex ? 'white' : 'white/60'}
                            _hover={{ bg: i === activeIndex ? 'white' : 'white/80' }}
                            transition="background 0.2s"
                            border="none"
                            cursor="pointer"
                            aria-label={`Перейти к слайду ${i + 1}`}
                        />
                    ))}
                </Flex>
            )}
        </Box>
    );
}
