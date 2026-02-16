import { Head, Link } from '@inertiajs/react';
import { Box, Flex, Text, Badge, SimpleGrid, Heading } from '@chakra-ui/react';
import { LuSearch } from 'react-icons/lu';
import UserLayout from '@/Pages/User/UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import EmptyState from '@/components/common/EmptyState';
import ProductCard from '@/components/product/ProductCard';
import ContentCard from '@/components/common/ContentCard';

/**
 * Страница результатов поиска /search?q=...
 *
 * Секции: Категории (бейджи), Бренды (бейджи), Товары (ProductCard),
 * Статьи (ContentCard), Новости (ContentCard).
 */
export default function SearchIndex({ query, results }) {
    const q = query || '';
    const res = results || {};
    const products = res.products || [];
    const categories = res.categories || [];
    const brands = res.brands || [];
    const articles = res.articles || [];
    const news = res.news || [];
    const hasAny = !!(products.length || categories.length || brands.length || articles.length || news.length);

    const breadcrumbs = [
        { label: 'Главная', url: '/' },
        { label: 'Поиск', url: '/search' },
        ...(q ? [{ label: `«${q}»` }] : []),
    ];

    return (
        <UserLayout>
            <Head>
                <title>{q ? `Поиск: ${q} — Pecado` : 'Поиск — Pecado'}</title>
                <meta name="description" content={`Результаты поиска${q ? ` по запросу «${q}»` : ''} в интернет-магазине Pecado`} />
            </Head>

            <Breadcrumbs items={breadcrumbs} />

            <PageHeader
                title={q ? (<>Результаты поиска: <Text as="span" color="pecado.500">«{q}»</Text></>) : 'Поиск'}
                subtitle={q && hasAny ? formatTotal(products.length + categories.length + brands.length + articles.length + news.length) : undefined}
            />

            {/* Empty state */}
            {!hasAny && q && (
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

            {/* Товары — ProductCard */}
            {products.length > 0 && (
                <SearchSection title="Товары">
                    <SimpleGrid columns={{ base: 1, sm: 2, md: 3 }} gap="4">
                        {products.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))}
                    </SimpleGrid>
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
 */
function SearchSection({ title, children }) {
    return (
        <Box mb="8">
            <Heading as="h2" size="lg" fontWeight="semibold" mb="4" color="fg">
                {title}
            </Heading>
            {children}
        </Box>
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
