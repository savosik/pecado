import { useRef, useState, useCallback } from 'react';
import { getResponsiveDefaultView } from '@/utils/compactFilters';
import { Head, Link, router } from '@inertiajs/react';
import { Box, Flex, Text, Badge, SimpleGrid, Heading } from '@chakra-ui/react';
import { LuSearch, LuInfo } from 'react-icons/lu';
import UserLayout from '@/Pages/User/UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import EmptyState from '@/components/common/EmptyState';
import ProductGrid from '@/Pages/User/Products/ProductGrid';
import ContentCard from '@/components/common/ContentCard';
import ProductPagination from '@/Pages/User/Products/ProductPagination';

const LS_INFINITE_SCROLL_KEY = 'search_infinite_scroll';

/**
 * Страница результатов поиска /search?q=...
 *
 * Секции: Категории (бейджи), Бренды (бейджи), Товары (ProductCard),
 * Статьи (ContentCard), Новости (ContentCard).
 *
 * Поддерживает два режима пагинации: классическую и бесконечную прокрутку.
 */
export default function SearchIndex({ query, results, productsMeta, availability }) {
    const q = query || '';
    const avail = availability || 'all';
    const res = results || {};
    const initialProducts = res.products || [];
    const categories = res.categories || [];
    const brands = res.brands || [];
    const articles = res.articles || [];
    const news = res.news || [];

    // ─── Состояние для бесконечной прокрутки ───
    const [accumulatedProducts, setAccumulatedProducts] = useState(initialProducts);
    const [currentMeta, setCurrentMeta] = useState(productsMeta);
    const [loadingMore, setLoadingMore] = useState(false);
    // На мобиле — list view по умолчанию, на md+ — grid
    const view = useRef(getResponsiveDefaultView()).current;

    const [infiniteScroll, setInfiniteScroll] = useState(() => {
        try {
            return localStorage.getItem(LS_INFINITE_SCROLL_KEY) === '1';
        } catch {
            return false;
        }
    });

    // Сброс накопленных товаров при смене серверных данных (новый поисковый запрос)
    // React автоматически вызовет ре-рендер при смене props
    const [prevQuery, setPrevQuery] = useState(q);
    const [prevInitialProducts, setPrevInitialProducts] = useState(initialProducts);
    if (q !== prevQuery || initialProducts !== prevInitialProducts) {
        setPrevQuery(q);
        setPrevInitialProducts(initialProducts);
        setAccumulatedProducts(initialProducts);
        setCurrentMeta(productsMeta);
    }

    // Фактические товары: в режиме infinite scroll — накопленные, иначе — серверные
    const products = infiniteScroll ? accumulatedProducts : initialProducts;
    const meta = infiniteScroll ? currentMeta : productsMeta;

    const hasAny = !!(products.length || categories.length || brands.length || articles.length || news.length);

    const breadcrumbs = [
        { label: 'Главная', url: '/' },
        { label: 'Поиск', url: '/search' },
        ...(q ? [{ label: `«${q}»` }] : []),
    ];

    /** Переход на другую страницу (классическая пагинация) */
    const handlePageChange = useCallback((page) => {
        router.get('/search', { q, page, availability: avail }, {
            preserveState: false,
            preserveScroll: false,
        });
    }, [q, avail]);

    /** Смена фильтра наличия — перезагружаем результаты с первой страницы */
    const handleAvailabilityChange = useCallback((next) => {
        if (next === avail) return;
        router.get('/search', { q, availability: next }, {
            preserveState: false,
            preserveScroll: true,
        });
    }, [q, avail]);

    /** Загрузить ещё (бесконечная прокрутка) */
    const handleLoadMore = useCallback(() => {
        if (!currentMeta || currentMeta.current_page >= currentMeta.last_page || loadingMore) {
            return;
        }

        setLoadingMore(true);
        const nextPage = currentMeta.current_page + 1;

        fetch(`/search?q=${encodeURIComponent(q)}&page=${nextPage}&type=products&availability=${avail}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((data) => {
                const newProducts = data.results?.products || [];

                // Мердж с дедупликацией по id
                setAccumulatedProducts((prev) => {
                    const existingIds = new Set(prev.map((p) => p.id));
                    const unique = newProducts.filter((p) => !existingIds.has(p.id));
                    return [...prev, ...unique];
                });

                // Обновляем meta
                if (data.productsMeta) {
                    setCurrentMeta((prevMeta) => ({
                        ...data.productsMeta,
                        from: prevMeta?.from ?? 1,
                    }));
                }

                setLoadingMore(false);
            })
            .catch((err) => {
                console.error('Ошибка загрузки товаров:', err);
                setLoadingMore(false);
            });
    }, [q, avail, currentMeta, loadingMore]);

    /** Переключение режима бесконечной прокрутки */
    const handleInfiniteScrollToggle = useCallback((enabled) => {
        setInfiniteScroll(enabled);
        try {
            if (enabled) {
                localStorage.setItem(LS_INFINITE_SCROLL_KEY, '1');
            } else {
                localStorage.removeItem(LS_INFINITE_SCROLL_KEY);
            }
        } catch {
            // localStorage недоступен
        }

        // При выключении infinite scroll — сбросить к серверным данным
        if (!enabled) {
            setAccumulatedProducts(initialProducts);
            setCurrentMeta(productsMeta);
        }
    }, [initialProducts, productsMeta]);

    // Общее количество (с учётом pagination total)
    const totalProducts = meta?.total ?? products.length;
    const totalResults = totalProducts + categories.length + brands.length + articles.length + news.length;

    return (
        <UserLayout fluid>
            <Head>
                <title>{q ? `Поиск: ${q} — Pecado` : 'Поиск — Pecado'}</title>
                <meta name="description" content={`Результаты поиска${q ? ` по запросу «${q}»` : ''} в интернет-магазине Pecado`} />
            </Head>

            {/* Текстовая шапка — с боковыми отступами на мобиле */}
            <Box px={{ base: '3', md: '0' }}>
                <Breadcrumbs items={breadcrumbs} />

                <PageHeader
                    title={q ? (<>Результаты поиска: <Text as="span" color="pecado.500">«{q}»</Text></>) : 'Поиск'}
                    subtitle={q && hasAny ? formatTotal(totalResults) : undefined}
                />

                {/* Empty state — только в режиме «Все»; при активном фильтре пустоту
                    показывает секция товаров со своим сообщением и контролом */}
                {!hasAny && q && avail === 'all' && (
                    <EmptyState
                        icon={LuSearch}
                        title="По вашему запросу ничего не найдено"
                        description="Попробуйте изменить запрос или использовать другие ключевые слова"
                        action={{ label: 'На главную', href: '/' }}
                    />
                )}

                {/* Начальное состояние без запроса */}
                {!q && (
                    <EmptyState
                        icon={LuSearch}
                        title="Введите поисковый запрос"
                        description="Попробуйте найти товары, бренды, категории или статьи"
                    />
                )}
            </Box>

            {/* Категории — бейджи */}
            {categories.length > 0 && (
                <SearchSection title="Категории">
                    <BadgeList items={categories} baseUrl="/categories" colorPalette="pecado" />
                </SearchSection>
            )}

            {/* Бренды — бейджи */}
            {brands.length > 0 && (
                <SearchSection title="Бренды">
                    <BadgeList items={brands} baseUrl="/brands" colorPalette="gray" />
                </SearchSection>
            )}

            {/* Товары — ProductGrid (идентично каталогу). На base — list view от края до края.
                Секция остаётся видимой при активном фильтре, даже если он дал пустой результат,
                чтобы пользователь мог переключить режим обратно. */}
            {q && meta && (products.length > 0 || avail !== 'all') && (
                <SearchSection title="Товары" fluid>
                    <Box px={{ base: '3', md: '0' }} mb="5">
                        <AvailabilityFilter value={avail} onChange={handleAvailabilityChange} />
                    </Box>

                    {products.length === 0 && (
                        <Box px={{ base: '3', md: '0' }}>
                            <EmptyState
                                icon={LuSearch}
                                title="Нет товаров с выбранным наличием"
                                description="Попробуйте выбрать «Все», чтобы увидеть остальные товары по запросу"
                            />
                        </Box>
                    )}

                    {products.length > 0 && meta?.no_exact_match && (
                        <Flex
                            align="flex-start"
                            gap="3"
                            p="4"
                            mb="4"
                            bg="orange.50"
                            borderLeft="4px solid"
                            borderColor="orange.400"
                            borderRadius="md"
                            _dark={{ bg: 'orange.900/30', borderColor: 'orange.300' }}
                        >
                            <Box color="orange.500" mt="0.5" _dark={{ color: 'orange.300' }}>
                                <LuInfo size={20} />
                            </Box>
                            <Box>
                                <Text fontWeight="semibold" color="fg">
                                    Точного совпадения по запросу «{q}» не найдено
                                </Text>
                            </Box>
                        </Flex>
                    )}

                    {products.length > 0 && (
                        <>
                            <ProductGrid
                                products={products}
                                view={view}
                                templateColumns={{
                                    base: 'repeat(2, minmax(0, 1fr))',
                                    md: 'repeat(3, minmax(0, 1fr))',
                                    lg: 'repeat(4, minmax(0, 1fr))',
                                    xl: 'repeat(5, minmax(0, 1fr))',
                                }}
                            />

                            {/* Пагинация товаров */}
                            {meta && (
                                <ProductPagination
                                    meta={meta}
                                    onPageChange={handlePageChange}
                                    onLoadMore={handleLoadMore}
                                    loadingMore={loadingMore}
                                    infiniteScroll={infiniteScroll}
                                    onInfiniteScrollToggle={handleInfiniteScrollToggle}
                                />
                            )}
                        </>
                    )}
                </SearchSection>
            )}

            {/* Статьи — ContentCard */}
            {articles.length > 0 && (
                <SearchSection title="Статьи">
                    <SimpleGrid columns={{ base: 1, sm: 2, lg: 3 }} gap="6">
                        {articles.map((article) => (
                            <ContentCard
                                key={article.id}
                                title={article.title}
                                excerpt={article.excerpt}
                                image={article.image_url}
                                date={article.published_at}
                                url={`/articles/${article.slug}`}
                            />
                        ))}
                    </SimpleGrid>
                </SearchSection>
            )}

            {/* Новости — ContentCard */}
            {news.length > 0 && (
                <SearchSection title="Новости">
                    <SimpleGrid columns={{ base: 1, sm: 2, lg: 3 }} gap="6">
                        {news.map((item) => (
                            <ContentCard
                                key={item.id}
                                title={item.title}
                                excerpt={item.excerpt}
                                date={item.published_at}
                                url={`/news/${item.slug}`}
                            />
                        ))}
                    </SimpleGrid>
                </SearchSection>
            )}
        </UserLayout>
    );
}

/**
 * Заголовок секции результатов.
 *
 * @param {{ title: string, children: React.ReactNode, fluid?: boolean }} props
 *   fluid=true — заголовок имеет горизонтальный паддинг на base, а content идёт от края до края
 *   (для секций с list-карточками).
 */
function SearchSection({ title, children, fluid = false }) {
    return (
        <Box mb="8">
            <Heading
                as="h2"
                size={{ base: 'md', md: 'lg' }}
                fontWeight="bold"
                mb="4"
                color="fg"
                px={fluid ? { base: '3', md: '0' } : undefined}
            >
                {title}
            </Heading>
            {fluid ? children : <Box px={{ base: '3', md: '0' }}>{children}</Box>}
        </Box>
    );
}

/**
 * Сегмент-контрол фильтра товаров по наличию.
 *
 * Режимы: все / в наличии / в наличии + предзаказ. Стилизован в фирменной
 * палитре pecado, адаптивен (на мобиле переносится и сжимает подписи).
 *
 * @param {{ value: 'all'|'in_stock'|'in_stock_preorder', onChange: (v: string) => void }} props
 */
function AvailabilityFilter({ value, onChange }) {
    const options = [
        { value: 'all', label: 'Все' },
        { value: 'in_stock', label: 'В наличии' },
        { value: 'in_stock_preorder', label: 'В наличии + предзаказ' },
    ];

    return (
        <Flex
            display="inline-flex"
            align="center"
            gap="1"
            p="1"
            bg="bg.muted"
            borderRadius="full"
            border="1px solid"
            borderColor="border.muted"
            flexWrap="wrap"
            maxW="full"
            role="group"
            aria-label="Фильтр товаров по наличию"
        >
            {options.map((opt) => {
                const active = value === opt.value;
                return (
                    <Box
                        as="button"
                        type="button"
                        key={opt.value}
                        onClick={() => onChange(opt.value)}
                        aria-pressed={active}
                        px={{ base: '3', md: '4' }}
                        py="1.5"
                        fontSize={{ base: 'xs', md: 'sm' }}
                        fontWeight="medium"
                        lineHeight="1.2"
                        whiteSpace="nowrap"
                        borderRadius="full"
                        cursor="pointer"
                        transition="background-color 0.15s, color 0.15s, box-shadow 0.15s"
                        color={active ? 'white' : 'fg.muted'}
                        bg={active ? 'pecado.500' : 'transparent'}
                        boxShadow={active ? 'sm' : 'none'}
                        _hover={active ? { bg: 'pecado.600' } : { bg: 'bg.subtle', color: 'fg' }}
                        _focusVisible={{ outline: '2px solid', outlineColor: 'pecado.500', outlineOffset: '2px' }}
                    >
                        {opt.label}
                    </Box>
                );
            })}
        </Flex>
    );
}

/**
 * Список бейджей-ссылок (категории, бренды).
 */
function BadgeList({ items, baseUrl, colorPalette }) {
    return (
        <Flex gap="2" flexWrap="wrap">
            {items.map((item) => (
                <Badge
                    key={item.id}
                    asChild
                    colorPalette={colorPalette}
                    variant="subtle"
                    px="3"
                    py="1.5"
                    borderRadius="full"
                    cursor="pointer"
                    fontSize="sm"
                    _hover={{ opacity: 0.8 }}
                >
                    <Link href={`${baseUrl}/${item.slug}`}>
                        {item.name}
                    </Link>
                </Badge>
            ))}
        </Flex>
    );
}

/**
 * Форматирование итога «Найдено N результатов» с правильным склонением.
 */
function formatTotal(count) {
    const n = count % 100;
    const n1 = count % 10;
    if (n > 10 && n < 20) return `Найдено ${count} результатов`;
    if (n1 === 1) return `Найден ${count} результат`;
    if (n1 >= 2 && n1 <= 4) return `Найдено ${count} результата`;
    return `Найдено ${count} результатов`;
}
