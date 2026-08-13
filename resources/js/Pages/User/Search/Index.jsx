import { Head, Link, usePage } from '@inertiajs/react';
import { Box, Flex, Text, Badge, SimpleGrid, Heading } from '@chakra-ui/react';
import { LuSearch } from 'react-icons/lu';

import UserLayout from '@/Pages/User/UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import EmptyState from '@/components/common/EmptyState';
import ContentCard from '@/components/common/ContentCard';
import SearchProducts from './SearchProducts';

/**
 * Страница результатов поиска /search?q=...
 *
 * Товары — полноценный каталожный блок (фильтры, фасеты, сортировки, вид,
 * пагинация) поверх релевантной выдачи поиска. Категории и бренды — компактные
 * чипы над ним, статьи и новости — карточками под ним.
 */
export default function SearchIndex() {
    const {
        query,
        results = {},
        initialFilters = {},
        sortOptions = [],
        appName = 'Pecado',
        auth,
        currency,
    } = usePage().props;

    const q = query || '';
    const categories = results.categories || [];
    const brands = results.brands || [];
    const articles = results.articles || [];
    const news = results.news || [];

    const isAuthenticated = !!auth?.user;
    const currencySymbol = currency?.symbol || '₽';
    const currencyCode = currency?.code || 'RUB';

    const hasContent = !!(articles.length || news.length);

    const breadcrumbs = [
        { label: 'Главная', url: '/' },
        { label: 'Поиск', url: '/search' },
        ...(q ? [{ label: `«${q}»` }] : []),
    ];

    return (
        <UserLayout fluid>
            <Head>
                <title>{q ? `Поиск: ${q} — ${appName}` : `Поиск — ${appName}`}</title>
                <meta name="description" content={`Результаты поиска${q ? ` по запросу «${q}»` : ''} в интернет-магазине ${appName}`} />
                <meta name="robots" content="noindex, follow" />
            </Head>

            <Box px={{ base: '3', md: '0' }}>
                <Breadcrumbs items={breadcrumbs} />

                <PageHeader
                    title={q ? (<>Результаты поиска: <Text as="span" color="pecado.500">«{q}»</Text></>) : 'Поиск'}
                />

                {/* Начальное состояние без запроса */}
                {!q && (
                    <EmptyState
                        icon={LuSearch}
                        title="Введите поисковый запрос"
                        description="Попробуйте найти товары, бренды, категории или статьи"
                        action={{ label: 'В каталог', href: '/products' }}
                    />
                )}

                {/* Категории и бренды — компактные ряды чипов над товарами */}
                {q && categories.length > 0 && (
                    <ChipRow title="Категории" items={categories} baseUrl="/categories" colorPalette="pecado" />
                )}
                {q && brands.length > 0 && (
                    <ChipRow title="Бренды" items={brands} baseUrl="/brands" colorPalette="gray" />
                )}
            </Box>

            {/* Товары — каталожный интерфейс */}
            {q && (
                <SearchProducts
                    key={q}
                    q={q}
                    initialFilters={initialFilters}
                    sortOptions={sortOptions}
                    isAuthenticated={isAuthenticated}
                    currencySymbol={currencySymbol}
                    currencyCode={currencyCode}
                />
            )}

            {/* Статьи и новости */}
            {q && hasContent && (
                <Box px={{ base: '3', md: '0' }}>
                    {articles.length > 0 && (
                        <ContentSection title="Статьи">
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
                        </ContentSection>
                    )}

                    {news.length > 0 && (
                        <ContentSection title="Новости">
                            {news.map((item) => (
                                <ContentCard
                                    key={item.id}
                                    title={item.title}
                                    excerpt={item.excerpt}
                                    date={item.published_at}
                                    url={`/news/${item.slug}`}
                                />
                            ))}
                        </ContentSection>
                    )}
                </Box>
            )}
        </UserLayout>
    );
}

/**
 * Компактный ряд чипов-ссылок с подписью слева (категории, бренды).
 */
function ChipRow({ title, items, baseUrl, colorPalette }) {
    return (
        <Flex gap="2" align="center" flexWrap="wrap" mb="3">
            <Text fontSize="sm" color="fg.muted" minW="20" flexShrink="0">
                {title}
            </Text>
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
 * Секция контентных карточек (статьи, новости).
 */
function ContentSection({ title, children }) {
    return (
        <Box mb="8">
            <Heading as="h2" size={{ base: 'md', md: 'lg' }} fontWeight="bold" mb="4" color="fg">
                {title}
            </Heading>
            <SimpleGrid columns={{ base: 1, sm: 2, lg: 3 }} gap="6">
                {children}
            </SimpleGrid>
        </Box>
    );
}
