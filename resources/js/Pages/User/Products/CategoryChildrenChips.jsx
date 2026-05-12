import { useEffect, useRef, useState } from 'react';
import { Box, Flex, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';

/**
 * CategoryChildrenChips — компактный ряд подкатегорий под заголовком категории.
 *
 * Один горизонтальный ряд без переноса; на узких экранах — touch-скролл,
 * на десктопе — скролл колесом/тачпадом. Скроллбар скрыт; края с fade-маской,
 * чтобы было видно, что справа/слева есть ещё чипы.
 *
 * @param {{
 *   categories: Array<{ id: number, name: string, slug: string, count: number }>,
 * }} props
 */
export default function CategoryChildrenChips({ categories = [] }) {
    const scrollerRef = useRef(null);
    const [overflow, setOverflow] = useState({ left: false, right: false });

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
                style={{
                    WebkitMaskImage: maskGradient,
                    maskImage: maskGradient,
                    scrollbarWidth: 'none',
                }}
                css={{
                    '&::-webkit-scrollbar': { display: 'none' },
                }}
            >
                {categories.map((cat) => (
                    <Link
                        key={cat.id}
                        href={`/categories/${cat.slug}`}
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
