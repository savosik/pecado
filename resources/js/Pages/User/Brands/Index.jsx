import { useEffect, useMemo, useRef, useState } from 'react';
import { Box, Flex, HStack, Input, Spinner, Text, VStack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuSearch } from 'react-icons/lu';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';

/* ─── helpers ─── */

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

const FeaturedBrandCard = ({ brand }) => {
    const hasLogo = !!brand.logo_url;
    const initials = (brand.name || '??').slice(0, 2).toUpperCase();
    return (
        <Box
            borderRadius="lg"
            border="1px solid"
            borderColor="border.muted"
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
                    overflow="hidden"
                    borderBottom="1px solid"
                    borderColor="border.muted"
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

const sortBrandsAlpha = (a, b) => {
    const na = (a.name || '').trim();
    const nb = (b.name || '').trim();
    if (!na && !nb) return 0;
    if (!na) return 1;
    if (!nb) return -1;
    const la = /^[A-Za-z]/.test(na);
    const lb = /^[A-Za-z]/.test(nb);
    if (la && !lb) return -1;
    if (!la && lb) return 1;
    return na.localeCompare(nb, 'ru-RU');
};

const buildBrandUrl = (slug) => `/brands/${slug}`;

/* ─── Main Component ─── */

export default function BrandsIndex({ seo }) {
    const [brands, setBrands] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');
    const abortRef = useRef(null);

    useEffect(() => {
        setLoading(true);
        setError(null);
        if (abortRef.current) abortRef.current.abort();
        abortRef.current = new AbortController();
        const signal = abortRef.current.signal;

        fetch('/api/catalog/brands', { signal })
            .then((r) => r.json())
            .then((data) => {
                setBrands(Array.isArray(data) ? data : (data?.data ?? []));
                setLoading(false);
            })
            .catch((e) => {
                if (e.name !== 'AbortError') {
                    setError('Не удалось загрузить список брендов');
                    setLoading(false);
                }
            });

        return () => abortRef.current?.abort();
    }, []);

    const isSearching = searchTerm.trim() !== '';

    const flatSearchBrands = useMemo(() => {
        if (!isSearching) return [];
        const q = searchTerm.toLowerCase();
        const matches = [];
        brands.forEach((b) => {
            const isMatch = (b.name || '').toLowerCase().includes(q);
            const children = Array.isArray(b.children) ? b.children : [];
            const childMatches = children.filter((c) => (c.name || '').toLowerCase().includes(q));
            if (isMatch) {
                matches.push({ ...b, searchIsChild: false });
            } else if (childMatches.length > 0) {
                childMatches.forEach((c) => matches.push({ ...c, searchIsChild: true, parentRef: b }));
            }
        });
        return matches;
    }, [brands, searchTerm, isSearching]);

    const featuredBrands = useMemo(() =>
        brands.filter((b) => b.is_featured).sort(sortBrandsAlpha),
    [brands]);

    const ownBrands = useMemo(() =>
        brands.filter((b) => b.category === 'own'),
    [brands]);

    const otherBrands = useMemo(() =>
        brands.filter((b) => b.category !== 'own'),
    [brands]);

    const otherBrandsByLetter = useMemo(() => {
        const map = {};
        for (const b of otherBrands) {
            if (b.is_featured) continue;
            const name = (b.name || '').trim();
            if (!name) continue;
            const letter = getFirstLetter(name);
            if (!map[letter]) map[letter] = [];
            map[letter].push(b);
        }
        for (const k of Object.keys(map)) {
            map[k].sort(sortBrandsAlpha);
        }
        return map;
    }, [otherBrands]);

    const breadcrumbs = [
        { label: 'Главная', href: '/' },
        { label: 'Бренды' },
    ];

    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader title="Бренды" subtitle="Все бренды магазина" />

            {/* Поиск */}
            <Flex position="relative" maxW="sm" mb="8">
                <Input
                    placeholder="Поиск брендов..."
                    borderRadius="lg"
                    bg="gray.50"
                    _dark={{ bg: 'gray.800' }}
                    pr="9"
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                />
                <Box position="absolute" right="3" top="50%" transform="translateY(-50%)" color="gray.400" pointerEvents="none">
                    <LuSearch size={16} />
                </Box>
            </Flex>

            {loading && (
                <Flex justify="center" py="16">
                    <Spinner size="lg" color="pecado.500" />
                </Flex>
            )}

            {error && (
                <Text color="red.500" fontSize="sm">{error}</Text>
            )}

            {!loading && !error && (
                <Box spaceY="10">
                    {isSearching ? (
                        /* ── Результаты поиска ── */
                        <Box>
                            <Text fontSize="md" fontWeight="600" mb="4">
                                Результаты поиска по брендам
                            </Text>
                            {flatSearchBrands.length > 0 ? (
                                <Box
                                    display="grid"
                                    gridTemplateColumns={{ base: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(4, 1fr)', xl: 'repeat(5, 1fr)' }}
                                    gap="4"
                                >
                                    {flatSearchBrands.map((b) => (
                                        <Link key={b.id} href={buildBrandUrl(b.slug)}>
                                            <Box
                                                p="3"
                                                borderRadius="md"
                                                _hover={{ bg: 'gray.50' }}
                                                _dark={{ _hover: { bg: 'gray.800' } }}
                                                transition="background 0.2s"
                                            >
                                                <Text
                                                    fontSize="sm"
                                                    fontWeight={b.searchIsChild ? '500' : '600'}
                                                    color="gray.800"
                                                    _dark={{ color: 'gray.100' }}
                                                >
                                                    {b.searchIsChild ? b.name : <BrandName name={b.name} tags={b.tags} />}
                                                </Text>
                                                {b.searchIsChild && (
                                                    <Text fontSize="xs" color="gray.500" mt="1">
                                                        Линейка {b.parentRef?.name}
                                                    </Text>
                                                )}
                                            </Box>
                                        </Link>
                                    ))}
                                </Box>
                            ) : (
                                <Box textAlign="center" py="12">
                                    <Text fontSize="sm" color="gray.400">
                                        По запросу «{searchTerm}» ничего не найдено
                                    </Text>
                                </Box>
                            )}
                        </Box>
                    ) : (
                        <>
                            {/* ── Популярные бренды ── */}
                            {featuredBrands.length > 0 && (
                                <Box>
                                    <Text fontSize="sm" fontWeight="600" mb="3">Популярные бренды</Text>
                                    <Box
                                        display="grid"
                                        gridTemplateColumns={{ base: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', md: 'repeat(4, 1fr)', lg: 'repeat(6, 1fr)', xl: 'repeat(8, 1fr)' }}
                                        gap="4"
                                    >
                                        {featuredBrands.map((b) => (
                                            <Link key={b.id} href={buildBrandUrl(b.slug)}>
                                                <FeaturedBrandCard brand={b} />
                                            </Link>
                                        ))}
                                    </Box>
                                </Box>
                            )}

                            {/* ── Собственные бренды ── */}
                            {ownBrands.length > 0 && (
                                <Box>
                                    <Text fontSize="md" fontWeight="600" mb="4" textTransform="uppercase">Собственные бренды</Text>
                                    <Box
                                        display="grid"
                                        gridTemplateColumns={{ base: 'repeat(2, 1fr)', sm: 'repeat(4, 1fr)', md: 'repeat(5, 1fr)', lg: 'repeat(6, 1fr)', xl: 'repeat(8, 1fr)' }}
                                        gap="3"
                                    >
                                        {ownBrands.filter((b) => !b.is_featured).sort(sortBrandsAlpha).map((b) => {
                                            const hasChildren = b.children && b.children.length > 0;

                                            const CardContent = (
                                                <Box
                                                    bg="bg"
                                                    border="1px solid"
                                                    borderColor="border.muted"
                                                    borderRadius="md"
                                                    overflow="hidden"
                                                    _hover={hasChildren ? { borderColor: 'gray.300' } : { shadow: 'sm', borderColor: 'gray.300' }}
                                                    transition="all 0.2s"
                                                    display="flex"
                                                    flexDirection="column"
                                                    h="24"
                                                    cursor={hasChildren ? 'default' : 'pointer'}
                                                >
                                                    <Flex flex="1" align="center" justify="center" p="2" overflow="hidden">
                                                        {b.logo_url ? (
                                                            <Box as="img" src={b.logo_url} maxH="100%" maxW="100%" objectFit="contain" />
                                                        ) : (
                                                            <Text fontWeight="bold" fontSize="lg" color="gray.300">
                                                                {b.name.slice(0, 2).toUpperCase()}
                                                            </Text>
                                                        )}
                                                    </Flex>
                                                    <Flex bg="gray.50" px="2" py="1.5" justify="center" align="center" borderTop="1px solid" borderColor="border.muted">
                                                        <Text fontSize="xs" fontWeight="500" color="gray.500" _dark={{ color: 'gray.300' }} truncate>
                                                            {b.name}
                                                        </Text>
                                                    </Flex>
                                                </Box>
                                            );

                                            return (
                                                <Box key={b.id} position="relative" role="group" _hover={{ zIndex: 20 }}>
                                                    {hasChildren ? CardContent : (
                                                        <Link href={buildBrandUrl(b.slug)}>
                                                            {CardContent}
                                                        </Link>
                                                    )}

                                                    {/* Дропдаун с линейками при ховере */}
                                                    {hasChildren && (
                                                        <Box
                                                            position="absolute"
                                                            top="-2"
                                                            left="-2"
                                                            right="-2"
                                                            bg="bg"
                                                            shadow="xl"
                                                            borderRadius="md"
                                                            border="1px solid"
                                                            borderColor="border.muted"
                                                            opacity="0"
                                                            visibility="hidden"
                                                            transform="scale(0.95)"
                                                            transformOrigin="top center"
                                                            _groupHover={{ opacity: 1, visibility: 'visible', transform: 'scale(1)' }}
                                                            transition="all 0.15s ease-out"
                                                            zIndex="10"
                                                            overflow="hidden"
                                                        >
                                                            <Link href={buildBrandUrl(b.slug)}>
                                                                <Flex
                                                                    direction="column"
                                                                    align="center"
                                                                    justify="center"
                                                                    p="3"
                                                                    borderBottom="1px solid"
                                                                    borderColor="border.muted"
                                                                    _hover={{ bg: 'gray.50' }}
                                                                    _dark={{ borderColor: 'gray.700', _hover: { bg: 'gray.700' } }}
                                                                >
                                                                    {b.logo_url ? (
                                                                        <Box as="img" src={b.logo_url} h="6" mb="2" objectFit="contain" />
                                                                    ) : (
                                                                        <Text fontWeight="bold" fontSize="sm" mb="1" truncate>{b.name}</Text>
                                                                    )}
                                                                    <HStack gap="1">
                                                                        <Text fontSize="xs" color="red.500" fontWeight="500">{b.name}</Text>
                                                                        <Text fontSize="xs" bg="green.500" color="white" px="1" borderRadius="sm">Own</Text>
                                                                    </HStack>
                                                                </Flex>
                                                            </Link>
                                                            <VStack align="stretch" gap="0" py="1">
                                                                {b.children.map((child) => (
                                                                    <Link key={child.id} href={buildBrandUrl(child.slug)}>
                                                                        <Box
                                                                            px="3"
                                                                            py="1.5"
                                                                            _hover={{ bg: 'gray.50' }}
                                                                            _dark={{ _hover: { bg: 'gray.700' } }}
                                                                        >
                                                                            <Text
                                                                                fontSize="xs"
                                                                                color="gray.600"
                                                                                _dark={{ color: 'gray.300' }}
                                                                                _hover={{ color: 'pecado.500' }}
                                                                                transition="colors"
                                                                            >
                                                                                {child.name}
                                                                            </Text>
                                                                        </Box>
                                                                    </Link>
                                                                ))}
                                                            </VStack>
                                                        </Box>
                                                    )}
                                                </Box>
                                            );
                                        })}
                                    </Box>
                                </Box>
                            )}

                            {/* ── Прочие бренды по буквам ── */}
                            {Object.keys(otherBrandsByLetter).length > 0 && (
                                <Box>
                                    {ownBrands.length > 0 && (
                                        <Text fontSize="md" fontWeight="600" mb="4" textTransform="uppercase">Прочие бренды</Text>
                                    )}
                                    <Box
                                        display="grid"
                                        gridTemplateColumns={{ base: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)', xl: 'repeat(5, 1fr)' }}
                                        gap="6"
                                    >
                                        {Object.keys(otherBrandsByLetter).sort(sortLetters).map((letter) => (
                                            <Box key={letter}>
                                                <Text
                                                    fontSize="xs"
                                                    fontWeight="600"
                                                    color="gray.400"
                                                    textTransform="uppercase"
                                                    letterSpacing="wide"
                                                    mb="2"
                                                >
                                                    {letter}
                                                </Text>
                                                <VStack align="stretch" gap="1">
                                                    {otherBrandsByLetter[letter].map((b) => (
                                                        <Link key={b.id} href={buildBrandUrl(b.slug)}>
                                                            <Text
                                                                fontSize="sm"
                                                                color="gray.600"
                                                                _hover={{ color: 'pecado.500' }}
                                                                _dark={{ color: 'gray.400', _hover: { color: 'pecado.300' } }}
                                                                transition="colors 0.15s"
                                                                truncate
                                                            >
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

                            {brands.length === 0 && (
                                <Box textAlign="center" py="16">
                                    <Text fontSize="sm" color="gray.400">Нет брендов</Text>
                                </Box>
                            )}
                        </>
                    )}
                </Box>
            )}
        </UserLayout>
    );
}
