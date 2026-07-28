import { useEffect, useRef, useState } from 'react';
import { Box, Flex, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';

/**
 * CategoryChildrenChips — компактный ряд подкатегорий под заголовком категории.
 *
 * Один горизонтальный ряд без переноса; на узких экранах — touch-скролл,
 * на десктопе — колесо мыши (вертикальное → горизонтальное), тачпад и
 * перетаскивание «за чипы». Скроллбар скрыт; края с fade-маской,
 * чтобы было видно, что справа/слева есть ещё чипы.
 *
 * @param {{
 *   categories: Array<{ id: number, name: string, slug: string, count: number }>,
 * }} props
 */
export default function CategoryChildrenChips({ categories = [] }) {
    const scrollerRef = useRef(null);
    const [overflow, setOverflow] = useState({ left: false, right: false });
    const [dragging, setDragging] = useState(false);
    // Данные текущего перетаскивания; movedRef гасит клик по чипу после drag
    const dragRef = useRef({ active: false, startX: 0, startScroll: 0 });
    const movedRef = useRef(false);

    useEffect(() => {
        const el = scrollerRef.current;
        if (!el) return;

        const update = () => {
            const left = el.scrollLeft > 2;
            const right = el.scrollLeft + el.clientWidth < el.scrollWidth - 2;
            setOverflow((prev) => (prev.left === left && prev.right === right ? prev : { left, right }));
        };

        update();
        el.addEventListener('scroll', update, { passive: true });
        const ro = new ResizeObserver(update);
        ro.observe(el);

        return () => {
            el.removeEventListener('scroll', update);
            ro.disconnect();
        };
    }, [categories]);

    // Вертикальное колесо мыши прокручивает ряд по горизонтали.
    // Слушатель нативный (не onWheel), иначе React вешает его как passive и preventDefault не сработает.
    useEffect(() => {
        const el = scrollerRef.current;
        if (!el) return;

        const onWheel = (e) => {
            if (e.ctrlKey) return; // зум страницы не перехватываем
            if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return; // горизонтальный жест тачпада — браузеру
            if (el.scrollWidth <= el.clientWidth) return;

            const next = el.scrollLeft + e.deltaY;
            const max = el.scrollWidth - el.clientWidth;
            // На краях отдаём событие странице, чтобы она продолжила вертикальный скролл
            if ((next < 0 && el.scrollLeft <= 0) || (next > max && el.scrollLeft >= max)) return;

            e.preventDefault();
            el.scrollLeft = next;
        };

        el.addEventListener('wheel', onWheel, { passive: false });
        return () => el.removeEventListener('wheel', onWheel);
    }, [categories]);

    // Перетаскивание мышью «за чипы» (на touch работает нативный скролл)
    useEffect(() => {
        if (!dragging) return;

        const onMove = (e) => {
            const el = scrollerRef.current;
            if (!el || !dragRef.current.active) return;
            const dx = e.clientX - dragRef.current.startX;
            if (Math.abs(dx) > 4) movedRef.current = true;
            el.scrollLeft = dragRef.current.startScroll - dx;
        };

        const onUp = () => {
            dragRef.current.active = false;
            setDragging(false);
        };

        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', onUp);

        return () => {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', onUp);
        };
    }, [dragging]);

    const handlePointerDown = (e) => {
        if (e.pointerType !== 'mouse' || e.button !== 0) return;
        const el = scrollerRef.current;
        if (!el || el.scrollWidth <= el.clientWidth) return;

        dragRef.current = { active: true, startX: e.clientX, startScroll: el.scrollLeft };
        movedRef.current = false;
        setDragging(true);
    };

    // После перетаскивания клик по чипу не должен уводить на категорию
    const handleClickCapture = (e) => {
        if (!movedRef.current) return;
        movedRef.current = false;
        e.preventDefault();
        e.stopPropagation();
    };

    if (!categories || categories.length === 0) return null;

    // Маска для fade: слева/справа в зависимости от наличия переполнения
    const maskGradient = (() => {
        const start = overflow.left ? 'transparent 0, #000 24px' : '#000 0';
        const end = overflow.right ? '#000 calc(100% - 24px), transparent 100%' : '#000 100%';
        return `linear-gradient(to right, ${start}, ${end})`;
    })();

    return (
        <Box mb="3" position="relative">
            <Flex
                ref={scrollerRef}
                gap="2"
                align="center"
                overflowX="auto"
                overflowY="hidden"
                pb="1"
                onPointerDown={handlePointerDown}
                onClickCapture={handleClickCapture}
                style={{
                    WebkitMaskImage: maskGradient,
                    maskImage: maskGradient,
                    scrollbarWidth: 'none',
                    cursor: dragging ? 'grabbing' : undefined,
                    userSelect: dragging ? 'none' : undefined,
                    overscrollBehaviorX: 'contain',
                }}
                css={{
                    '&::-webkit-scrollbar': { display: 'none' },
                    ...(dragging ? { '& a': { cursor: 'grabbing' } } : {}),
                }}
            >
                {categories.map((cat) => (
                    <Link
                        key={cat.id}
                        href={`/categories/${cat.slug}`}
                        draggable={false}
                        style={{ textDecoration: 'none', flexShrink: 0 }}
                    >
                        <Flex
                            align="center"
                            gap="1.5"
                            px="3"
                            py="1.5"
                            borderRadius="full"
                            border="1px solid"
                            borderColor="gray.200"
                            bg="white"
                            color="gray.700"
                            fontSize="sm"
                            lineHeight="1.2"
                            whiteSpace="nowrap"
                            transition="all 0.15s"
                            _dark={{ borderColor: 'gray.700', bg: 'gray.800', color: 'gray.200' }}
                            _hover={{
                                borderColor: 'pecado.300',
                                bg: 'pecado.50',
                                color: 'pecado.700',
                                _dark: { borderColor: 'pecado.500', bg: 'pecado.900/30', color: 'pecado.200' },
                            }}
                        >
                            <Text as="span">{cat.name}</Text>
                            <Text as="span" fontSize="xs" color="gray.400" _dark={{ color: 'gray.500' }}>
                                {cat.count}
                            </Text>
                        </Flex>
                    </Link>
                ))}
            </Flex>
        </Box>
    );
}
