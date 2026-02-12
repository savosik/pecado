import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Input, IconButton,
    Tabs, Spinner,
} from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import {
    LuX, LuSearch, LuGrid2X2, LuTag, LuChevronRight, LuArrowLeft,
    LuFolder,
} from 'react-icons/lu';

/* ─── helpers ─── */

const getFirstLetter = (name) => {
    if (!name || !name[0]) return '#';
    const ch = name[0];
    return /^[A-Za-zА-Яа-яЁё]/.test(ch) ? ch.toLocaleUpperCase('ru-RU') : '#';
};

const sortLetters = (a, b) => {
    if (a === '#') return 1;
    if (b === '#') return -1;
    const la = /^[A-Za-z]/.test(a);
    const lb = /^[A-Za-z]/.test(b);
    if (la && !lb) return -1;
    if (!la && lb) return 1;
    return a.localeCompare(b, 'ru-RU');
};

const buildCategoryUrl = (id) => `/products?category_id=${encodeURIComponent(id)}&include_descendants=1`;
const buildBrandUrl = (id) => `/products?brand_id=${encodeURIComponent(id)}`;

/* ─── CategoryColumn ─── */

function CategoryColumn({ category, onNavigate }) {
    const children = Array.isArray(category.children) ? category.children : [];
    const searchMatches = category.searchMatches || [];

    return (
        <Box minW="0">
            {searchMatches.length === 0 && (
                <Text as={Link} href={buildCategoryUrl(category.id)} onClick={onNavigate}
                    fontSize="sm" fontWeight="600" mb="2" display="block"
                    _hover={{ textDecoration: 'underline' }}
                >
                    {category.name}
                </Text>
            )}
            <VStack align="stretch" gap="1">
                {searchMatches.length > 0
                    ? searchMatches.map((m) => (
                        <Link key={m.id} href={buildCategoryUrl(m.id)} onClick={onNavigate}>
                            <Box>
                                {m.searchPath && (
                                    <Text fontSize="xs" color="gray.400" mb="0.5">
                                        {m.searchPath.map((n) => n.name).join(' › ')}
                                    </Text>
                                )}
                                <Text fontSize="sm" fontWeight="500">{m.name}</Text>
                            </Box>
                        </Link>
                    ))
                    : children.map((c) => (
                        <Link key={c.id} href={buildCategoryUrl(c.id)} onClick={onNavigate}>
                            <Text fontSize="sm" color="gray.600" _hover={{ color: 'pecado.500' }}
                                _dark={{ color: 'gray.400', _hover: { color: 'pecado.300' } }}
                                transition="colors 0.15s" truncate
                            >
                                {c.name}
                            </Text>
                        </Link>
                    ))
                }
            </VStack>
        </Box>
    );
}

/* ─── Main CatalogPanel ─── */

export default function CatalogPanel({ open, onClose }) {
    const [activeTab, setActiveTab] = useState('categories');
    const [searchTerm, setSearchTerm] = useState('');
    const [categories, setCategories] = useState([]);
    const [brands, setBrands] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const abortRef = useRef(null);
    const [activeRootIndex, setActiveRootIndex] = useState(0);
    const [mobilePath, setMobilePath] = useState([]);

    /* ── Fetch data when panel opens ── */
    useEffect(() => {
        if (!open) return;
        setLoading(true);
        setError(null);
        if (abortRef.current) abortRef.current.abort();
        abortRef.current = new AbortController();
        const signal = abortRef.current.signal;

        const fetchCats = fetch('/api/catalog/categories', { signal })
            .then((r) => r.json())
            .then((data) => {
                setCategories(data?.categories ?? (Array.isArray(data) ? data : []));
            });
        const fetchBrands = fetch('/api/catalog/brands', { signal })
            .then((r) => r.json())
            .then((data) => {
                setBrands(Array.isArray(data) ? data : (data?.data ?? []));
            });

        Promise.allSettled([fetchCats, fetchBrands])
            .then(() => setLoading(false))
            .catch(() => setLoading(false));
    }, [open]);

    /* reset active root when categories load */
    useEffect(() => {
        if (categories.length > 0) { setActiveRootIndex(0); setMobilePath([]); }
    }, [categories]);

    /* Escape key */
    useEffect(() => {
        if (!open) return;
        const handler = (e) => { if (e.key === 'Escape') onClose?.(); };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [open, onClose]);

    /* ── mobile hierarchy ── */
    const mobileCurrentList = useMemo(() => {
        if (!mobilePath.length) return categories;
        const last = mobilePath[mobilePath.length - 1];
        return Array.isArray(last.children) ? last.children : [];
    }, [mobilePath, categories]);

    const hasChildren = (n) => Array.isArray(n.children) && n.children.length > 0;

    /* ── filtered categories (search) ── */
    const filteredCategories = useMemo(() => {
        if (!searchTerm.trim()) return categories;
        const q = searchTerm.toLowerCase();
        const matches = [];
        const collect = (node, path = []) => {
            const name = (node.name || '').toLowerCase();
            const cur = [...path, node];
            if (name.includes(q)) matches.push({ ...node, searchPath: cur, isDirectMatch: true });
            (Array.isArray(node.children) ? node.children : []).forEach((c) => collect(c, cur));
        };
        categories.forEach((cat) => collect(cat));

        const grouped = {};
        matches.forEach((m) => {
            const root = m.searchPath[0];
            if (!grouped[root.id]) grouped[root.id] = { ...root, children: [], searchMatches: [] };
            grouped[root.id].searchMatches.push(m);
        });
        return Object.values(grouped);
    }, [categories, searchTerm]);

    /* ── filtered brands (search) ── */
    const filteredBrands = useMemo(() => {
        const list = Array.isArray(brands) ? brands : [];
        if (!searchTerm.trim()) return list;
        const q = searchTerm.toLowerCase();
        return list.filter((b) => (b.name || '').toLowerCase().includes(q));
    }, [brands, searchTerm]);

    /* ── brands grouped by letter ── */
    const brandsByLetter = useMemo(() => {
        const map = {};
        for (const b of filteredBrands) {
            const name = (b.name || '').trim();
            if (!name) continue;
            const letter = getFirstLetter(name);
            if (!map[letter]) map[letter] = [];
            map[letter].push(b);
        }
        for (const k of Object.keys(map)) {
            map[k].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        }
        return map;
    }, [filteredBrands]);

    if (!open) return null;

    const tabItems = [
        { value: 'categories', label: 'Категории', icon: <LuGrid2X2 /> },
        { value: 'brands', label: 'Бренды', icon: <LuTag /> },
    ];

    return (
        <Box position="fixed" inset="0" zIndex="60" role="dialog" aria-modal="true">
            {/* Backdrop */}
            <Box position="absolute" inset="0" bg="blackAlpha.400" onClick={onClose} />

            {/* Panel */}
            <Box
                position="absolute"
                left="0" right="0"
                top={{ base: '0', sm: '56px' }}
                mx="auto"
                w="100%"
                maxW={{ sm: '1360px' }}
                borderRadius={{ base: '0', sm: '0 0 xl xl' }}
                bg="white"
                _dark={{ bg: 'gray.900' }}
                shadow="xl"
                display="flex"
                flexDirection="column"
                maxH={{ base: '100vh', sm: '85vh' }}
            >
                {/* Header: Tabs + Search + Close */}
                <Flex
                    px={{ base: '3', sm: '6' }}
                    py="3"
                    borderBottom="1px solid"
                    borderColor="gray.100"
                    _dark={{ borderColor: 'gray.700' }}
                    align="center"
                    gap="3"
                    flexWrap={{ base: 'wrap', md: 'nowrap' }}
                >
                    <Tabs.Root
                        value={activeTab}
                        onValueChange={(d) => setActiveTab(d.value)}
                        flex="1"
                        variant="line"
                        size="sm"
                    >
                        <Tabs.List>
                            {tabItems.map((t) => (
                                <Tabs.Trigger key={t.value} value={t.value}>
                                    <HStack gap="1.5">
                                        {t.icon}
                                        <Text display={{ base: 'none', sm: 'inline' }}>{t.label}</Text>
                                    </HStack>
                                </Tabs.Trigger>
                            ))}
                        </Tabs.List>
                    </Tabs.Root>

                    <Flex position="relative" flex="1" maxW={{ md: 'sm' }} order={{ base: 3, md: 0 }} w={{ base: '100%', md: 'auto' }}>
                        <Input
                            placeholder={activeTab === 'categories' ? 'Поиск категорий...' : 'Поиск брендов...'}
                            size="sm"
                            borderRadius="lg"
                            bg="gray.50"
                            _dark={{ bg: 'gray.800' }}
                            pr="9"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                        />
                        <Box position="absolute" right="2" top="50%" transform="translateY(-50%)" color="gray.400" pointerEvents="none">
                            <LuSearch size={16} />
                        </Box>
                    </Flex>

                    <IconButton
                        aria-label="Закрыть"
                        variant="ghost"
                        colorPalette="gray"
                        size="sm"
                        onClick={onClose}
                        order={{ base: 2, md: 0 }}
                    >
                        <LuX />
                    </IconButton>
                </Flex>

                {/* Body */}
                <Box px={{ base: '3', sm: '6' }} py="4" overflowY="auto" flex="1">
                    {loading && (
                        <Flex justify="center" py="12">
                            <Spinner size="lg" color="pecado.500" />
                        </Flex>
                    )}
                    {error && <Text color="red.500" fontSize="sm">{error}</Text>}

                    {!loading && !error && activeTab === 'categories' && (
                        <>
                            {/* ── Mobile: hierarchical navigation ── */}
                            <Box display={{ base: 'block', lg: 'none' }} {...(searchTerm.trim() ? { display: 'none' } : {})}>
                                {mobilePath.length > 0 && (
                                    <Flex align="center" gap="2" mb="3">
                                        <IconButton
                                            aria-label="Назад"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setMobilePath((p) => p.slice(0, -1))}
                                        >
                                            <LuArrowLeft />
                                        </IconButton>
                                        <Text fontSize="sm" color="gray.500" truncate>
                                            {mobilePath.map((n) => n.name).join(' / ')}
                                        </Text>
                                    </Flex>
                                )}
                                <VStack align="stretch" gap="0" borderRadius="lg" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }} overflow="hidden">
                                    {mobileCurrentList.map((node, idx) => (
                                        <Flex
                                            key={node.id}
                                            align="center"
                                            justify="space-between"
                                            px="4" py="3"
                                            gap="3"
                                            borderTop={idx > 0 ? '1px solid' : 'none'}
                                            borderColor="gray.100"
                                            _dark={{ borderColor: 'gray.700' }}
                                        >
                                            <HStack gap="3" minW="0" flex="1">
                                                {mobilePath.length === 0 && (
                                                    <Flex
                                                        w="6" h="6" align="center" justify="center"
                                                        borderRadius="md" bg="gray.100" flexShrink="0"
                                                        _dark={{ bg: 'gray.700' }}
                                                    >
                                                        {node.icon_url
                                                            ? <Box as="img" src={node.icon_url} w="4" h="4" borderRadius="sm" />
                                                            : <LuFolder size={14} />
                                                        }
                                                    </Flex>
                                                )}
                                                <Text
                                                    as={Link}
                                                    href={buildCategoryUrl(node.id)}
                                                    onClick={onClose}
                                                    fontSize="sm"
                                                    truncate
                                                >
                                                    {node.name}
                                                </Text>
                                            </HStack>
                                            {hasChildren(node) && (
                                                <IconButton
                                                    aria-label="Развернуть"
                                                    variant="ghost"
                                                    size="xs"
                                                    colorPalette="gray"
                                                    onClick={() => setMobilePath((p) => [...p, node])}
                                                >
                                                    <LuChevronRight />
                                                </IconButton>
                                            )}
                                        </Flex>
                                    ))}
                                    {mobileCurrentList.length === 0 && (
                                        <Box px="4" py="6" textAlign="center">
                                            <Text fontSize="sm" color="gray.400">Нет категорий</Text>
                                        </Box>
                                    )}
                                </VStack>
                            </Box>

                            {/* ── Search results grid (any screen) ── */}
                            {searchTerm.trim() && (
                                <Box
                                    display="grid"
                                    gridTemplateColumns={{ base: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(3, 1fr)' }}
                                    gap="8"
                                >
                                    {filteredCategories.map((cat) => (
                                        <CategoryColumn key={cat.id} category={cat} onNavigate={onClose} />
                                    ))}
                                    {filteredCategories.length === 0 && (
                                        <Text fontSize="sm" color="gray.400" gridColumn="1 / -1" textAlign="center" py="8">
                                            Ничего не найдено
                                        </Text>
                                    )}
                                </Box>
                            )}

                            {/* ── Desktop: sidebar + subcategories ── */}
                            {!searchTerm.trim() && (
                                <Flex display={{ base: 'none', lg: 'flex' }} gap="6">
                                    {/* Sidebar — root categories */}
                                    <Box w="72" flexShrink="0" borderRight="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }} pr="4">
                                        <VStack align="stretch" gap="1">
                                            {categories.map((root, idx) => (
                                                <Flex
                                                    key={root.id}
                                                    as="button"
                                                    align="center"
                                                    gap="3"
                                                    px="3" py="2"
                                                    borderRadius="lg"
                                                    fontSize="sm"
                                                    cursor="pointer"
                                                    textAlign="left"
                                                    bg={activeRootIndex === idx ? 'pecado.600' : 'transparent'}
                                                    color={activeRootIndex === idx ? 'white' : undefined}
                                                    _hover={activeRootIndex === idx ? {} : { bg: 'gray.50' }}
                                                    _dark={activeRootIndex === idx
                                                        ? { bg: 'pecado.600', color: 'white' }
                                                        : { _hover: { bg: 'gray.800' } }
                                                    }
                                                    transition="all 0.15s"
                                                    onMouseEnter={() => setActiveRootIndex(idx)}
                                                    onClick={() => setActiveRootIndex(idx)}
                                                >
                                                    <Flex
                                                        w="6" h="6" align="center" justify="center"
                                                        borderRadius="md" flexShrink="0"
                                                        bg={activeRootIndex === idx ? 'whiteAlpha.200' : 'gray.100'}
                                                        _dark={{ bg: activeRootIndex === idx ? 'whiteAlpha.200' : 'gray.700' }}
                                                    >
                                                        {root.icon_url
                                                            ? <Box as="img" src={root.icon_url} w="4" h="4" borderRadius="sm" />
                                                            : <LuFolder size={14} />
                                                        }
                                                    </Flex>
                                                    <Text truncate>{root.name}</Text>
                                                </Flex>
                                            ))}
                                        </VStack>
                                    </Box>

                                    {/* Subcategories panel */}
                                    <Box flex="1" minW="0">
                                        {categories[activeRootIndex] && (
                                            <>
                                                <Text
                                                    as={Link}
                                                    href={buildCategoryUrl(categories[activeRootIndex].id)}
                                                    onClick={onClose}
                                                    fontSize="md"
                                                    fontWeight="600"
                                                    mb="4"
                                                    display="block"
                                                    _hover={{ color: 'pecado.500' }}
                                                >
                                                    {categories[activeRootIndex].name}
                                                </Text>
                                                <Box
                                                    display="grid"
                                                    gridTemplateColumns={{ base: '1fr', md: 'repeat(2, 1fr)', xl: 'repeat(3, 1fr)' }}
                                                    gap="6"
                                                >
                                                    {(categories[activeRootIndex].children || []).map((second) => (
                                                        <CategoryColumn key={second.id} category={second} onNavigate={onClose} />
                                                    ))}
                                                </Box>
                                            </>
                                        )}
                                    </Box>
                                </Flex>
                            )}
                        </>
                    )}

                    {!loading && !error && activeTab === 'brands' && (
                        <Box>
                            {Object.keys(brandsByLetter).length > 0 ? (
                                <Box
                                    display="grid"
                                    gridTemplateColumns={{ base: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)', xl: 'repeat(5, 1fr)' }}
                                    gap="6"
                                >
                                    {Object.keys(brandsByLetter).sort(sortLetters).map((letter) => (
                                        <Box key={letter}>
                                            <Text fontSize="xs" fontWeight="600" color="gray.400" textTransform="uppercase" letterSpacing="wide" mb="2">
                                                {letter}
                                            </Text>
                                            <VStack align="stretch" gap="1">
                                                {brandsByLetter[letter].map((b) => (
                                                    <Link key={b.id} href={buildBrandUrl(b.id)} onClick={onClose}>
                                                        <Text
                                                            fontSize="sm"
                                                            color="gray.600"
                                                            _hover={{ color: 'pecado.500' }}
                                                            _dark={{ color: 'gray.400', _hover: { color: 'pecado.300' } }}
                                                            transition="colors 0.15s"
                                                            truncate
                                                        >
                                                            {b.name}
                                                        </Text>
                                                    </Link>
                                                ))}
                                            </VStack>
                                        </Box>
                                    ))}
                                </Box>
                            ) : (
                                <Box textAlign="center" py="12">
                                    <Text fontSize="sm" color="gray.400">
                                        {searchTerm.trim() ? 'Ничего не найдено' : 'Нет брендов'}
                                    </Text>
                                </Box>
                            )}
                        </Box>
                    )}
                </Box>
            </Box>
        </Box>
    );
}
