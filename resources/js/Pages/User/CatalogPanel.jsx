import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Input, IconButton,
    Tabs, Spinner,
} from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import {
    LuX, LuSearch, LuGrid2X2, LuTag, LuChevronRight, LuArrowLeft,
    LuFolder, LuLayoutGrid,
} from 'react-icons/lu';

/* ─── helpers ─── */

const darkenColor = (hex, percent = 30) => {
    if (!hex) return hex;
    hex = hex.replace('#', '');
    if (hex.length !== 6) return hex;
    const r = parseInt(hex.substring(0, 2), 16);
    const g = parseInt(hex.substring(2, 4), 16);
    const b = parseInt(hex.substring(4, 6), 16);
    const f = 1 - percent / 100;
    return `#${Math.max(0, Math.floor(r * f)).toString(16).padStart(2, '0')}${Math.max(0, Math.floor(g * f)).toString(16).padStart(2, '0')}${Math.max(0, Math.floor(b * f)).toString(16).padStart(2, '0')}`;
};

const TagBadge = ({ tag }) => {
    const textColor = tag.color ? darkenColor(tag.color, 35) : undefined;
    return (
        <Text
            as="sup"
            fontSize="0.6rem"
            fontWeight="400"
            ml="1"
            px="1"
            py="0.5"
            borderRadius="sm"
            style={{
                color: textColor || tag.color || 'var(--chakra-colors-gray-500)',
                backgroundColor: tag.color ? `${tag.color}20` : 'var(--chakra-colors-gray-100)',
            }}
        >
            {tag.name}
        </Text>
    );
};

const BrandName = ({ name, tags }) => {
    if (!tags || !Array.isArray(tags) || tags.length === 0) return name;
    return (<>{name}<TagBadge tag={tags[0]} /></>);
};

const getBrandGradient = (name) => {
    const gradients = [
        'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
        'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
        'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
        'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
        'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)',
        'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)',
        'linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)',
        'linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%)',
        'linear-gradient(135deg, #ff8a80 0%, #ffb74d 100%)',
    ];
    const hash = (name || '').split('').reduce((a, c) => { a = ((a << 5) - a) + c.charCodeAt(0); return a & a; }, 0);
    return gradients[Math.abs(hash) % gradients.length];
};

const FeaturedBrandCard = ({ brand }) => {
    const hasLogo = !!brand.logo_url;
    const initials = (brand.name || '??').slice(0, 2).toUpperCase();
    return (
        <Box
            borderRadius="lg"
            border="1px solid"
            borderColor="gray.100"
            _dark={{ borderColor: 'gray.700' }}
            overflow="hidden"
            _hover={{ shadow: 'lg' }}
            transition="all 0.2s"
            h="100%"
            display="flex"
            flexDirection="column"
        >
            {hasLogo ? (
                <Flex
                    aspectRatio="1"
                    w="100%"
                    align="center"
                    justify="center"
                    bg="gray.50"
                    _dark={{ bg: 'gray.800' }}
                    overflow="hidden"
                    borderBottom="1px solid"
                    borderColor="gray.100"
                    _darkBorder={{ borderColor: 'gray.700' }}
                >
                    <Box as="img" src={brand.logo_url} alt="" maxH="100%" maxW="100%" objectFit="contain" />
                </Flex>
            ) : (
                <Flex
                    aspectRatio="1"
                    w="100%"
                    align="center"
                    justify="center"
                    position="relative"
                    overflow="hidden"
                    style={{ background: getBrandGradient(brand.name) }}
                >
                    {/* subtle pattern */}
                    <Box position="absolute" inset="0" opacity="0.1">
                        <Box position="absolute" top="2" right="2" w="8" h="8" border="2px solid white" borderRadius="md" />
                        <Box position="absolute" bottom="2" left="2" w="4" h="4" border="2px solid white" borderRadius="md" />
                    </Box>
                    <Text color="white" fontWeight="bold" fontSize="2xl" textShadow="0 2px 4px rgba(0,0,0,0.2)">
                        {initials}
                    </Text>
                </Flex>
            )}
            <Flex p="3" justify="center" align="center" flex="1">
                <Text fontSize="xs" fontWeight="500" textAlign="center" truncate>{brand.name}</Text>
            </Flex>
        </Box>
    );
};

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

const buildCategoryUrl = (slug) => `/categories/${slug}`;
const buildBrandUrl = (slug) => `/brands/${slug}`;
const buildSelectionUrl = (slug) => `/collections/${slug}`;

/* ─── CategoryColumn ─── */

function CategoryColumn({ category, onNavigate }) {
    const children = Array.isArray(category.children) ? category.children : [];
    const searchMatches = category.searchMatches || [];

    return (
        <Box minW="0">
            {searchMatches.length === 0 && (
                <Text as={Link} href={buildCategoryUrl(category.slug)} onClick={onNavigate}
                    fontSize="sm" fontWeight="600" mb="2" display="block"
                    _hover={{ textDecoration: 'underline' }}
                >
                    {category.name}
                </Text>
            )}
            <VStack align="stretch" gap="1">
                {searchMatches.length > 0
                    ? searchMatches.map((m) => (
                        <Link key={m.id} href={buildCategoryUrl(m.slug)} onClick={onNavigate}>
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
                        <Link key={c.id} href={buildCategoryUrl(c.slug)} onClick={onNavigate}>
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
    const [selections, setSelections] = useState([]);
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
        const fetchSelections = fetch('/api/catalog/selections', { signal })
            .then((r) => r.json())
            .then((data) => {
                setSelections(Array.isArray(data) ? data : (data?.data ?? []));
            });

        Promise.allSettled([fetchCats, fetchBrands, fetchSelections])
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
            if (name.includes(q)) matches.push({ ...node, slug: node.slug, searchPath: cur, isDirectMatch: true });
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

    /* ── featured brands ── */
    const featuredBrands = useMemo(() =>
        filteredBrands.filter((b) => b.is_featured).sort((a, b) => (a.name || '').localeCompare(b.name || '')),
        [filteredBrands]);

    /* ── own brands grouped by letter ── */
    const groupByLetter = (list) => {
        const map = {};
        for (const b of list) {
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
    };

    const ownBrandsByLetter = useMemo(() =>
        groupByLetter(filteredBrands.filter((b) => b.category === 'own')),
        [filteredBrands]);

    const otherBrandsByLetter = useMemo(() =>
        groupByLetter(filteredBrands.filter((b) => b.category !== 'own')),
        [filteredBrands]);

    /* ── filtered selections (search) ── */
    const filteredSelections = useMemo(() => {
        const list = Array.isArray(selections) ? selections : [];
        if (!searchTerm.trim()) return list;
        const q = searchTerm.toLowerCase();
        return list.filter((s) => (s.name || '').toLowerCase().includes(q));
    }, [selections, searchTerm]);

    if (!open) return null;

    const tabItems = [
        { value: 'categories', label: 'Категории', icon: <LuGrid2X2 /> },
        { value: 'brands', label: 'Бренды', icon: <LuTag /> },
        { value: 'selections', label: 'Подборки', icon: <LuLayoutGrid /> },
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
                <Box px={{ base: '3', sm: '6' }} pt="3" pb="2">
                    {/* Row 1: Tabs + Close */}
                    <Flex align="center" gap="3" mb="2">
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

                        <Flex position="relative" w="100%" maxW="sm" ml="auto" display={{ base: 'none', md: 'flex' }}>
                            <Input
                                placeholder={
                                    activeTab === 'categories'
                                        ? 'Поиск категорий...'
                                        : activeTab === 'brands'
                                            ? 'Поиск брендов...'
                                            : 'Поиск подборок...'
                                }
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
                        >
                            <LuX />
                        </IconButton>
                    </Flex>

                    {/* Row 2: Search (mobile only) */}
                    <Flex position="relative" w="100%" display={{ base: 'flex', md: 'none' }}>
                        <Input
                            placeholder={
                                activeTab === 'categories'
                                    ? 'Поиск категорий...'
                                    : activeTab === 'brands'
                                        ? 'Поиск брендов...'
                                        : 'Поиск подборок...'
                            }
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
                </Box>

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
                                                    href={buildCategoryUrl(node.slug)}
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
                                                    href={buildCategoryUrl(categories[activeRootIndex].slug)}
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
                                                    {[...(categories[activeRootIndex].children || [])]
                                                        .sort((a, b) => (b.children?.length || 0) - (a.children?.length || 0))
                                                        .map((second) => (
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
                        <Box spaceY="8">
                            {/* Featured brands cards */}
                            {featuredBrands.length > 0 && (
                                <Box>
                                    <Text fontSize="sm" fontWeight="600" mb="3">Популярные бренды</Text>
                                    <Box
                                        display="grid"
                                        gridTemplateColumns={{ base: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', md: 'repeat(4, 1fr)', lg: 'repeat(6, 1fr)', xl: 'repeat(8, 1fr)' }}
                                        gap="4"
                                    >
                                        {featuredBrands.map((b) => (
                                            <Link key={b.id} href={buildBrandUrl(b.slug)} onClick={onClose}>
                                                <FeaturedBrandCard brand={b} />
                                            </Link>
                                        ))}
                                    </Box>
                                </Box>
                            )}

                            {/* Own brands */}
                            {Object.keys(ownBrandsByLetter).length > 0 && (
                                <Box>
                                    <Text fontSize="md" fontWeight="600" mb="4">Собственные бренды</Text>
                                    <Box
                                        display="grid"
                                        gridTemplateColumns={{ base: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)', xl: 'repeat(5, 1fr)' }}
                                        gap="6"
                                    >
                                        {Object.keys(ownBrandsByLetter).sort(sortLetters).map((letter) => (
                                            <Box key={letter}>
                                                <Text fontSize="xs" fontWeight="600" color="gray.400" textTransform="uppercase" letterSpacing="wide" mb="2">{letter}</Text>
                                                <VStack align="stretch" gap="1">
                                                    {ownBrandsByLetter[letter].map((b) => (
                                                        <Link key={b.id} href={buildBrandUrl(b.slug)} onClick={onClose}>
                                                            <Text fontSize="sm" color="gray.600" _hover={{ color: 'pecado.500' }} _dark={{ color: 'gray.400', _hover: { color: 'pecado.300' } }} transition="colors 0.15s" truncate>
                                                                <BrandName name={b.name} tags={b.tags} />
                                                            </Text>
                                                        </Link>
                                                    ))}
                                                </VStack>
                                            </Box>
                                        ))}
                                    </Box>
                                </Box>
                            )}

                            {/* Other brands */}
                            <Box>
                                {Object.keys(ownBrandsByLetter).length > 0 && (
                                    <Text fontSize="md" fontWeight="600" mb="4">Прочие бренды</Text>
                                )}
                                {Object.keys(otherBrandsByLetter).length > 0 ? (
                                    <Box
                                        display="grid"
                                        gridTemplateColumns={{ base: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)', xl: 'repeat(5, 1fr)' }}
                                        gap="6"
                                    >
                                        {Object.keys(otherBrandsByLetter).sort(sortLetters).map((letter) => (
                                            <Box key={letter}>
                                                <Text fontSize="xs" fontWeight="600" color="gray.400" textTransform="uppercase" letterSpacing="wide" mb="2">{letter}</Text>
                                                <VStack align="stretch" gap="1">
                                                    {otherBrandsByLetter[letter].map((b) => (
                                                        <Link key={b.id} href={buildBrandUrl(b.slug)} onClick={onClose}>
                                                            <Text fontSize="sm" color="gray.600" _hover={{ color: 'pecado.500' }} _dark={{ color: 'gray.400', _hover: { color: 'pecado.300' } }} transition="colors 0.15s" truncate>
                                                                <BrandName name={b.name} tags={b.tags} />
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
                        </Box>
                    )}

                    {!loading && !error && activeTab === 'selections' && (
                        <Box>
                            {filteredSelections.length > 0 ? (
                                <Box
                                    display="grid"
                                    gridTemplateColumns={{ base: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(3, 1fr)', xl: 'repeat(4, 1fr)' }}
                                    gap="4"
                                >
                                    {filteredSelections.map((s) => (
                                        <Link key={s.id} href={buildSelectionUrl(s.slug)} onClick={onClose}>
                                            <Box
                                                p="4"
                                                borderRadius="lg"
                                                border="1px solid"
                                                borderColor="gray.100"
                                                _dark={{ borderColor: 'gray.700' }}
                                                _hover={{ borderColor: 'pecado.300', shadow: 'sm' }}
                                                transition="all 0.15s"
                                            >
                                                <Text fontSize="sm" fontWeight="600" mb="1">
                                                    {s.name}
                                                </Text>
                                                {s.short_description && (
                                                    <Text fontSize="xs" color="gray.500" lineClamp="2">
                                                        {s.short_description}
                                                    </Text>
                                                )}
                                            </Box>
                                        </Link>
                                    ))}
                                </Box>
                            ) : (
                                <Box textAlign="center" py="12">
                                    <Text fontSize="sm" color="gray.400">
                                        {searchTerm.trim() ? 'Ничего не найдено' : 'Нет подборок'}
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
