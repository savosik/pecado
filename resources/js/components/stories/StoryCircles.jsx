import { useState, useEffect, useCallback, useRef, lazy, Suspense } from 'react';
import { Box, Flex, Text, Image, IconButton } from '@chakra-ui/react';
import { LuChevronLeft, LuChevronRight } from 'react-icons/lu';

const StoryViewer = lazy(() => import('./StoryViewer'));

const VIEWED_KEY = 'pecado_viewed_stories';

/**
 * Получить набор просмотренных story IDs из localStorage.
 */
function getViewedIds() {
    try {
        const raw = localStorage.getItem(VIEWED_KEY);
        return raw ? new Set(JSON.parse(raw)) : new Set();
    } catch {
        return new Set();
    }
}

/**
 * Сохранить ID просмотренной story.
 */
function markViewed(storyId) {
    try {
        const ids = getViewedIds();
        ids.add(storyId);
        localStorage.setItem(VIEWED_KEY, JSON.stringify([...ids]));
    } catch {
        // localStorage недоступен
    }
}

/**
 * StoryCircles — горизонтальная прокрутка круглых превью stories
 * с поддержкой свайпа (drag-to-scroll) и стрелок навигации.
 *
 * @param {Object} props
 * @param {Array} props.stories — массив stories [{id, name, slug, slides: [{media_url, ...}]}]
 */
export default function StoryCircles({ stories = [] }) {
    const [viewerOpen, setViewerOpen] = useState(false);
    const [selectedIndex, setSelectedIndex] = useState(0);
    const [viewedIds, setViewedIds] = useState(new Set());

    // ─── Scroll / drag state ────────────────────────────────────
    const scrollRef = useRef(null);
    const isDraggingRef = useRef(false);
    const dragStartXRef = useRef(0);
    const scrollStartRef = useRef(0);
    const hasDraggedRef = useRef(false);

    const [canScrollLeft, setCanScrollLeft] = useState(false);
    const [canScrollRight, setCanScrollRight] = useState(false);
    const [isHovered, setIsHovered] = useState(false);

    // ─── Update scroll boundary state ───────────────────────────
    const updateScrollState = useCallback(() => {
        const el = scrollRef.current;
        if (!el) return;
        setCanScrollLeft(el.scrollLeft > 1);
        setCanScrollRight(el.scrollLeft < el.scrollWidth - el.clientWidth - 1);
    }, []);

    useEffect(() => {
        const el = scrollRef.current;
        if (!el) return;
        updateScrollState();
        el.addEventListener('scroll', updateScrollState, { passive: true });
        window.addEventListener('resize', updateScrollState);
        return () => {
            el.removeEventListener('scroll', updateScrollState);
            window.removeEventListener('resize', updateScrollState);
        };
    }, [updateScrollState, stories.length]);

    // ─── Mouse drag-to-scroll ───────────────────────────────────
    const handleMouseDown = useCallback((e) => {
        const el = scrollRef.current;
        if (!el) return;
        isDraggingRef.current = true;
        hasDraggedRef.current = false;
        dragStartXRef.current = e.clientX;
        scrollStartRef.current = el.scrollLeft;
        el.style.cursor = 'grabbing';
        el.style.userSelect = 'none';
    }, []);

    const handleMouseMove = useCallback((e) => {
        if (!isDraggingRef.current) return;
        const el = scrollRef.current;
        if (!el) return;
        const dx = e.clientX - dragStartXRef.current;
        if (Math.abs(dx) > 5) hasDraggedRef.current = true;
        el.scrollLeft = scrollStartRef.current - dx;
    }, []);

    const handleMouseUp = useCallback(() => {
        isDraggingRef.current = false;
        const el = scrollRef.current;
        if (el) {
            el.style.cursor = '';
            el.style.userSelect = '';
        }
    }, []);

    // Global mousemove/mouseup so drag works even when pointer leaves container
    useEffect(() => {
        document.addEventListener('mousemove', handleMouseMove);
        document.addEventListener('mouseup', handleMouseUp);
        return () => {
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('mouseup', handleMouseUp);
        };
    }, [handleMouseMove, handleMouseUp]);

    // ─── Arrow navigation ───────────────────────────────────────
    const scrollByPage = useCallback((direction) => {
        const el = scrollRef.current;
        if (!el) return;
        const scrollAmount = el.clientWidth * 0.8;
        el.scrollBy({ left: direction * scrollAmount, behavior: 'smooth' });
    }, []);

    // Загрузить просмотренные из localStorage
    useEffect(() => {
        setViewedIds(getViewedIds());
    }, []);

    const handleClick = useCallback((index) => {
        // Не открывать viewer если пользователь drag-нул
        if (hasDraggedRef.current) {
            hasDraggedRef.current = false;
            return;
        }
        setSelectedIndex(index);
        setViewerOpen(true);
    }, []);

    const handleClose = useCallback(() => {
        setViewerOpen(false);
    }, []);

    const handleStoryViewed = useCallback((storyId) => {
        markViewed(storyId);
        setViewedIds((prev) => {
            const next = new Set(prev);
            next.add(storyId);
            return next;
        });
    }, []);

    if (!stories.length) return null;

    const showArrows = canScrollLeft || canScrollRight;

    return (
        <>
            <Box
                position="relative"
                mb="4"
                onMouseEnter={() => setIsHovered(true)}
                onMouseLeave={() => setIsHovered(false)}
            >
                <Flex
                    ref={scrollRef}
                    gap="4"
                    overflowX="auto"
                    py="2"
                    px="1"
                    cursor="grab"
                    onMouseDown={handleMouseDown}
                    css={{
                        '&::-webkit-scrollbar': { display: 'none' },
                        scrollbarWidth: 'none',
                    }}
                >
                    {stories.map((story, i) => {
                        const preview = story.slides?.[0]?.media_url;
                        const isViewed = viewedIds.has(story.id);

                        return (
                            <Box
                                key={story.id}
                                flexShrink="0"
                                cursor="pointer"
                                onClick={() => handleClick(i)}
                                role="button"
                                aria-label={`Открыть историю: ${story.name}`}
                                transition="transform 0.15s"
                                _hover={{ transform: 'scale(1.02)' }}
                                _active={{ transform: 'scale(0.98)' }}
                                w={{ base: 'calc(33.333% - 12px)', sm: '20%', md: '14.285%', lg: '11.111%' }}
                                minW={{ base: '100px', md: '110px' }}
                            >
                                {/* Rectangular card with gradient border */}
                                <Box
                                    w="100%"
                                    borderRadius="2xl"
                                    p="3px"
                                    background={
                                        isViewed
                                            ? 'gray.300'
                                            : 'linear-gradient(135deg, #a855f7 0%, #ec4899 50%, #f59e0b 100%)'
                                    }
                                    _dark={{
                                        background: isViewed
                                            ? 'gray.600'
                                            : 'linear-gradient(135deg, #a855f7 0%, #ec4899 50%, #f59e0b 100%)',
                                    }}
                                >
                                    <Box
                                        w="100%"
                                        position="relative"
                                        borderRadius="2xl"
                                        overflow="hidden"
                                        bg="white"
                                        _dark={{ bg: 'gray.900' }}
                                        css={{ aspectRatio: '143 / 255' }}
                                    >
                                        {preview ? (
                                            <Image
                                                src={preview}
                                                alt={story.name}
                                                w="100%"
                                                h="100%"
                                                objectFit="cover"
                                                loading="lazy"
                                                draggable="false"
                                            />
                                        ) : (
                                            <Box
                                                w="100%"
                                                h="100%"
                                                bg="gray.200"
                                                _dark={{ bg: 'gray.700' }}
                                                display="flex"
                                                alignItems="center"
                                                justifyContent="center"
                                            >
                                                <Text fontSize="2xl" opacity="0.5">📷</Text>
                                            </Box>
                                        )}

                                        {/* Name overlay at bottom */}
                                        {story.show_name !== false && (
                                            <Box
                                                position="absolute"
                                                bottom="0"
                                                left="0"
                                                right="0"
                                                bg="linear-gradient(to top, rgba(0,0,0,0.7), transparent)"
                                                px="2"
                                                pb="2"
                                                pt="6"
                                            >
                                                <Text
                                                    fontSize="xs"
                                                    fontWeight="600"
                                                    color="white"
                                                    lineClamp="2"
                                                    textShadow="0 1px 3px rgba(0,0,0,0.5)"
                                                >
                                                    {story.name}
                                                </Text>
                                            </Box>
                                        )}
                                    </Box>
                                </Box>
                            </Box>
                        );
                    })}
                </Flex>

                {/* Navigation arrows */}
                {showArrows && isHovered && (
                    <>
                        {canScrollLeft && (
                            <IconButton
                                aria-label="Прокрутить влево"
                                onClick={() => scrollByPage(-1)}
                                position="absolute"
                                left="1"
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
                        )}
                        {canScrollRight && (
                            <IconButton
                                aria-label="Прокрутить вправо"
                                onClick={() => scrollByPage(1)}
                                position="absolute"
                                right="1"
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
                        )}
                    </>
                )}
            </Box>

            {/* Lazy-loaded fullscreen viewer */}
            {viewerOpen && (
                <Suspense fallback={null}>
                    <StoryViewer
                        stories={stories}
                        initialIndex={selectedIndex}
                        onClose={handleClose}
                        onStoryViewed={handleStoryViewed}
                    />
                </Suspense>
            )}
        </>
    );
}
