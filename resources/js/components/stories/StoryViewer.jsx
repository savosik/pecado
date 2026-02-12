import { useEffect, useRef, useState, useCallback } from 'react';
import { Box, Flex, Text, IconButton } from '@chakra-ui/react';
import { LuX, LuChevronLeft, LuChevronRight } from 'react-icons/lu';

/**
 * StoryViewer — полноэкранный просмотр stories slide-by-slide.
 *
 * @param {Object} props
 * @param {Array} props.stories — массив stories с slides
 * @param {number} props.initialIndex — начальный индекс story
 * @param {Function} props.onClose — закрытие просмотра
 * @param {Function} [props.onStoryViewed] — callback при просмотре story
 */
export default function StoryViewer({ stories = [], initialIndex = 0, onClose, onStoryViewed }) {
    const [storyIndex, setStoryIndex] = useState(initialIndex);
    const [slideIndex, setSlideIndex] = useState(0);
    const [progress, setProgress] = useState(0);
    const [isPaused, setIsPaused] = useState(false);
    const progressRef = useRef(null);
    const videoRef = useRef(null);

    const story = stories[storyIndex];
    const slide = story?.slides?.[slideIndex];
    const totalSlides = story?.slides?.length ?? 0;

    // ─── Auto-progress ──────────────────────────────
    const clearTimer = useCallback(() => {
        if (progressRef.current) {
            clearInterval(progressRef.current);
            progressRef.current = null;
        }
    }, []);

    const goNextSlide = useCallback(() => {
        if (!story) return;

        if (slideIndex < totalSlides - 1) {
            setSlideIndex((i) => i + 1);
            setProgress(0);
        } else {
            // Story завершена
            onStoryViewed?.(story.id);
            if (storyIndex < stories.length - 1) {
                setStoryIndex((i) => i + 1);
                setSlideIndex(0);
                setProgress(0);
            } else {
                onClose?.();
            }
        }
    }, [story, slideIndex, totalSlides, storyIndex, stories.length, onClose, onStoryViewed]);

    const goPrevSlide = useCallback(() => {
        if (slideIndex > 0) {
            setSlideIndex((i) => i - 1);
            setProgress(0);
        } else if (storyIndex > 0) {
            const prevStory = stories[storyIndex - 1];
            setStoryIndex((i) => i - 1);
            setSlideIndex(prevStory.slides.length - 1);
            setProgress(0);
        }
    }, [slideIndex, storyIndex, stories]);

    useEffect(() => {
        if (!slide || isPaused) {
            clearTimer();
            return;
        }

        const duration = slide.duration || 5000;
        const interval = 50;
        const increment = (interval / duration) * 100;

        clearTimer();
        progressRef.current = setInterval(() => {
            setProgress((prev) => {
                const next = prev + increment;
                if (next >= 100) {
                    goNextSlide();
                    return 0;
                }
                return next;
            });
        }, interval);

        return clearTimer;
    }, [slide, isPaused, clearTimer, goNextSlide]);

    // ─── Keyboard ───────────────────────────────────
    useEffect(() => {
        const handleKey = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                onClose?.();
            } else if (e.key === 'ArrowRight' || e.key === ' ') {
                e.preventDefault();
                goNextSlide();
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                goPrevSlide();
            }
        };
        window.addEventListener('keydown', handleKey);
        return () => window.removeEventListener('keydown', handleKey);
    }, [goNextSlide, goPrevSlide, onClose]);

    // ─── Prevent body scroll ────────────────────────
    useEffect(() => {
        document.body.style.overflow = 'hidden';
        return () => { document.body.style.overflow = ''; };
    }, []);

    // ─── Click navigation ───────────────────────────
    const handleContentClick = useCallback((e) => {
        // Не перехватываем клики по кнопкам
        if (e.target.closest('button') || e.target.closest('a')) return;

        const rect = e.currentTarget.getBoundingClientRect();
        const clickX = e.clientX - rect.left;
        if (clickX < rect.width / 2) {
            goPrevSlide();
        } else {
            goNextSlide();
        }
    }, [goPrevSlide, goNextSlide]);

    // ─── Touch / pause ──────────────────────────────
    const handlePause = useCallback(() => setIsPaused(true), []);
    const handleResume = useCallback(() => setIsPaused(false), []);

    if (!story || !slide) return null;

    return (
        <Box
            position="fixed"
            inset="0"
            bg="black"
            zIndex="9999"
            display="flex"
            flexDirection="column"
        >
            {/* Progress bars */}
            <Flex
                position="absolute"
                top="0"
                left="0"
                right="0"
                zIndex="10"
                gap="1"
                p="3"
                pb="2"
            >
                {story.slides.map((_, i) => (
                    <Box
                        key={i}
                        flex="1"
                        h="3px"
                        bg="whiteAlpha.400"
                        borderRadius="full"
                        overflow="hidden"
                    >
                        <Box
                            h="100%"
                            bg="white"
                            borderRadius="full"
                            transition={i === slideIndex ? 'width 0.05s linear' : 'none'}
                            w={
                                i < slideIndex
                                    ? '100%'
                                    : i === slideIndex
                                        ? `${progress}%`
                                        : '0%'
                            }
                        />
                    </Box>
                ))}
            </Flex>

            {/* Story name */}
            <Box
                position="absolute"
                top="5"
                left="3"
                zIndex="10"
                pt="3"
            >
                <Text color="white" fontSize="sm" fontWeight="600" textShadow="0 1px 3px rgba(0,0,0,0.6)">
                    {story.name}
                </Text>
            </Box>

            {/* Close button */}
            <IconButton
                aria-label="Закрыть"
                onClick={onClose}
                position="absolute"
                top="5"
                right="3"
                zIndex="10"
                size="sm"
                variant="plain"
                color="white"
                bg="blackAlpha.500"
                _hover={{ bg: 'blackAlpha.700' }}
                borderRadius="full"
            >
                <LuX size={20} />
            </IconButton>

            {/* Slide content */}
            <Box
                flex="1"
                position="relative"
                cursor="pointer"
                onClick={handleContentClick}
                onMouseDown={handlePause}
                onMouseUp={handleResume}
                onMouseLeave={handleResume}
                onTouchStart={handlePause}
                onTouchEnd={handleResume}
                display="flex"
                alignItems="center"
                justifyContent="center"
                overflow="hidden"
            >
                {slide.media_type === 'video' ? (
                    <Box
                        as="video"
                        ref={videoRef}
                        src={slide.media_url}
                        autoPlay
                        muted
                        playsInline
                        maxW="100%"
                        maxH="100%"
                        objectFit="contain"
                    />
                ) : (
                    <Box
                        as="img"
                        src={slide.media_url}
                        alt={slide.title || 'Слайд'}
                        maxW="100%"
                        maxH="100%"
                        objectFit="contain"
                    />
                )}

                {/* Slide overlay text */}
                {(slide.title || slide.content) && (
                    <Box
                        position="absolute"
                        bottom="20"
                        left="0"
                        right="0"
                        px="6"
                        textAlign="center"
                        pointerEvents="none"
                    >
                        {slide.title && (
                            <Text
                                color="white"
                                fontSize="xl"
                                fontWeight="700"
                                textShadow="0 2px 8px rgba(0,0,0,0.7)"
                                mb="2"
                            >
                                {slide.title}
                            </Text>
                        )}
                        {slide.content && (
                            <Text
                                color="whiteAlpha.900"
                                fontSize="sm"
                                textShadow="0 1px 4px rgba(0,0,0,0.6)"
                            >
                                {slide.content}
                            </Text>
                        )}
                    </Box>
                )}

                {/* Button action */}
                {slide.button_url && slide.button_text && (
                    <Box
                        position="absolute"
                        bottom="6"
                        left="50%"
                        transform="translateX(-50%)"
                        zIndex="10"
                    >
                        <Box
                            as="a"
                            href={slide.button_url}
                            onClick={(e) => e.stopPropagation()}
                            display="inline-block"
                            bg="white"
                            color="gray.900"
                            fontWeight="600"
                            px="8"
                            py="3"
                            borderRadius="lg"
                            textAlign="center"
                            _hover={{ bg: 'gray.100' }}
                            transition="background 0.2s"
                            shadow="lg"
                            fontSize="sm"
                        >
                            {slide.button_text}
                        </Box>
                    </Box>
                )}
            </Box>

            {/* Desktop navigation arrows */}
            <IconButton
                aria-label="Предыдущий слайд"
                onClick={(e) => { e.stopPropagation(); goPrevSlide(); }}
                position="absolute"
                left="4"
                top="50%"
                transform="translateY(-50%)"
                zIndex="10"
                display={{ base: 'none', md: 'flex' }}
                size="lg"
                variant="plain"
                color="white"
                bg="blackAlpha.500"
                _hover={{ bg: 'blackAlpha.700' }}
                borderRadius="full"
            >
                <LuChevronLeft size={24} />
            </IconButton>

            <IconButton
                aria-label="Следующий слайд"
                onClick={(e) => { e.stopPropagation(); goNextSlide(); }}
                position="absolute"
                right="4"
                top="50%"
                transform="translateY(-50%)"
                zIndex="10"
                display={{ base: 'none', md: 'flex' }}
                size="lg"
                variant="plain"
                color="white"
                bg="blackAlpha.500"
                _hover={{ bg: 'blackAlpha.700' }}
                borderRadius="full"
            >
                <LuChevronRight size={24} />
            </IconButton>

            {/* Story thumbnails at bottom (when multiple stories) */}
            {stories.length > 1 && (
                <Flex
                    bg="linear-gradient(to top, rgba(0,0,0,0.8), transparent)"
                    p="4"
                    pt="6"
                    gap="2"
                    justify="center"
                    overflowX="auto"
                    css={{ '&::-webkit-scrollbar': { display: 'none' }, scrollbarWidth: 'none' }}
                >
                    {stories.map((s, i) => {
                        const thumb = s.slides?.[0]?.media_url;
                        const isActive = i === storyIndex;

                        return (
                            <Box
                                as="button"
                                key={s.id}
                                onClick={() => {
                                    setStoryIndex(i);
                                    setSlideIndex(0);
                                    setProgress(0);
                                }}
                                flexShrink="0"
                                w="50px"
                                h="70px"
                                borderRadius="lg"
                                overflow="hidden"
                                border="2px solid"
                                borderColor={isActive ? 'white' : 'whiteAlpha.400'}
                                opacity={isActive ? 1 : 0.6}
                                transition="all 0.2s"
                                _hover={{ opacity: 1 }}
                                cursor="pointer"
                            >
                                {thumb ? (
                                    <Box
                                        as="img"
                                        src={thumb}
                                        alt={s.name}
                                        w="100%"
                                        h="100%"
                                        objectFit="cover"
                                    />
                                ) : (
                                    <Box w="100%" h="100%" bg="gray.700" />
                                )}
                            </Box>
                        );
                    })}
                </Flex>
            )}
        </Box>
    );
}
