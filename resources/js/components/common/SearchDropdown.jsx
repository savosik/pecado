import { useMemo } from 'react';
import { Box, Flex, Text, Input, IconButton, Spinner } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuX, LuClock3, LuSearch, LuArrowRight } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import SearchSection from './SearchSection';

/**
 * Компактная карточка товара для дропдауна поиска.
 */
function ProductItem({ product, onClick }) {
    const price = product.price ?? product.base_price;
    const imageUrl = product.thumbnail || product.image_url || product.thumb_url || product.main_image;
    const inStock = (product.stock_quantity ?? 0) > 0;
    const inPreorder = (product.preorder_quantity ?? 0) > 0;

    // Статус наличия в регионе пользователя
    let stockLabel, stockColor;
    if (inStock) {
        stockLabel = 'В наличии';
        stockColor = 'green.500';
    } else if (inPreorder) {
        stockLabel = 'Предзаказ';
        stockColor = 'orange.500';
    } else {
        stockLabel = 'Нет в наличии';
        stockColor = 'red.500';
    }

    return (
        <Flex
            as={Link}
            href={`/products/${product.slug}`}
            onClick={onClick}
            align="center"
            gap="3"
            px="3"
            py="1.5"
            _hover={{ bg: 'gray.50' }}
            _dark={{ _hover: { bg: 'gray.700' } }}
            cursor="pointer"
            transition="background 0.15s"
        >
            {/* Миниатюра */}
            {imageUrl && (
                <Box
                    w="40px"
                    h="60px"
                    flexShrink="0"
                    borderRadius="md"
                    overflow="hidden"
                    bg="gray.100"
                    _dark={{ bg: 'gray.700' }}
                >
                    <Box as="img" src={imageUrl} alt={product.name} w="100%" h="100%" objectFit="cover" />
                </Box>
            )}
            {/* Название + бренд/артикул + цена + статус */}
            <Box flex="1" minW="0">
                <Text fontSize="sm" lineClamp="1" fontWeight="400">
                    {product.name}
                </Text>
                <Text fontSize="xs" color="gray.400" lineClamp="1">
                    {[product.brand_name, product.sku].filter(Boolean).join(' · ')}
                </Text>
                <Flex align="center" gap="2">
                    {price != null && (
                        <Text fontSize="xs" fontWeight="600" color={inStock ? undefined : 'gray.400'}>
                            {Number(price).toLocaleString('ru-RU')} ₽
                        </Text>
                    )}
                    <Text fontSize="2xs" color={stockColor}>{stockLabel}</Text>
                </Flex>
            </Box>
        </Flex>
    );
}

/**
 * Элемент списка-ссылки (бренды, категории, статьи, новости).
 */
function LinkItem({ href, children, icon, onClick }) {
    return (
        <Flex
            as={Link}
            href={href}
            onClick={onClick}
            align="center"
            gap="2"
            px="3"
            py="1.5"
            fontSize="sm"
            _hover={{ bg: 'gray.50' }}
            _dark={{ _hover: { bg: 'gray.700' } }}
            cursor="pointer"
            transition="background 0.15s"
        >
            {icon && <Box as="span" color="gray.400" flexShrink="0">{icon}</Box>}
            <Text lineClamp="1">{children}</Text>
        </Flex>
    );
}

/**
 * Элемент истории поиска.
 */
function HistoryItem({ item, onSelect, onDelete }) {
    return (
        <Flex
            align="center"
            gap="2"
            px="3"
            py="1.5"
            _hover={{ bg: 'gray.50' }}
            _dark={{ _hover: { bg: 'gray.700' } }}
            cursor="pointer"
            transition="background 0.15s"
            onClick={() => onSelect(item.query)}
        >
            <Box color="gray.400" flexShrink="0">
                <LuClock3 size={14} />
            </Box>
            <Text
                flex="1"
                fontSize="sm"
                lineClamp="1"
            >
                {item.query}
            </Text>
            <IconButton
                aria-label="Удалить"
                size="2xs"
                variant="ghost"
                colorPalette="gray"
                onClick={(e) => { e.stopPropagation(); onDelete(item.id); }}
                minW="5"
                h="5"
            >
                <LuX size={12} />
            </IconButton>
        </Flex>
    );
}

/**
 * SearchDropdown — выпадающий дропдаун с результатами поиска.
 *
 * Получает все данные из хука useSearch.
 */
export default function SearchDropdown({
    query,
    setQuery,
    loading,
    results,
    history,
    error,
    hasResults,
    isSmall,
    includeUnavailable,
    toggleIncludeUnavailable,
    deleteHistoryItem,
    clearAllHistory,
    setOpen,
    submitSearch,
}) {
    // Фильтрация товаров по наличию (клиентская)
    const filteredProducts = useMemo(() => {
        const products = results?.products ?? [];
        if (includeUnavailable) return products;
        return products.filter((p) => {
            const qty = p.available_quantity ?? p.stock_quantity ?? 0;
            return qty > 0 || p.is_preorder;
        });
    }, [results?.products, includeUnavailable]);

    const close = () => setOpen(false);

    const handleHistorySelect = (q) => {
        setQuery(q);
    };

    const handleSubmit = (e) => {
        e?.preventDefault?.();
        submitSearch();
    };

    // ─── Контент дропдауна ───────────────────────────────────
    const renderContent = () => {
        const trimmed = query.trim();

        // 1. Пустой запрос + есть история
        if (!trimmed && history.length > 0) {
            return (
                <SearchSection
                    title="Недавние запросы"
                    action={
                        <Text
                            as="button"
                            fontSize="2xs"
                            color="pecado.500"
                            _hover={{ textDecoration: 'underline' }}
                            cursor="pointer"
                            onClick={clearAllHistory}
                        >
                            Очистить всё
                        </Text>
                    }
                >
                    {history.map((item) => (
                        <HistoryItem
                            key={item.id}
                            item={item}
                            onSelect={handleHistorySelect}
                            onDelete={deleteHistoryItem}
                        />
                    ))}
                </SearchSection>
            );
        }

        // 2. Запрос < 2 символов
        if (trimmed.length > 0 && trimmed.length < 2) {
            return (
                <Flex align="center" justify="center" py="6" px="3">
                    <Text fontSize="sm" color="gray.400">Введите минимум 2 символа</Text>
                </Flex>
            );
        }

        // 3. Загрузка
        if (loading) {
            return (
                <Flex align="center" justify="center" gap="2" py="6">
                    <Spinner size="sm" color="gray.400" />
                    <Text fontSize="sm" color="gray.400">Поиск…</Text>
                </Flex>
            );
        }

        // 4. Ошибка
        if (error) {
            return (
                <Flex align="center" justify="center" py="6" px="3">
                    <Text fontSize="sm" color="red.500">{error}</Text>
                </Flex>
            );
        }

        // 5. Есть результаты
        if (hasResults) {
            return (
                <>
                    {/* Товары */}
                    {filteredProducts.length > 0 && (
                        <SearchSection title="Товары">
                            {filteredProducts.map((product) => (
                                <ProductItem key={product.id} product={product} onClick={close} />
                            ))}
                        </SearchSection>
                    )}

                    {/* Чекбокс «Включая отсутствующие» */}
                    {(results?.products?.length ?? 0) > 0 && (
                        <Box px="3" py="1">
                            <Checkbox
                                size="sm"
                                checked={includeUnavailable}
                                onCheckedChange={toggleIncludeUnavailable}
                            >
                                <Text fontSize="xs" color="gray.500">Включая отсутствующие</Text>
                            </Checkbox>
                        </Box>
                    )}

                    {/* Бренды */}
                    {results?.brands?.length > 0 && (
                        <SearchSection title="Бренды">
                            {results.brands.map((brand) => (
                                <LinkItem
                                    key={brand.id}
                                    href={`/brands/${brand.slug}`}
                                    onClick={close}
                                >
                                    {brand.name}
                                </LinkItem>
                            ))}
                        </SearchSection>
                    )}

                    {/* Категории */}
                    {results?.categories?.length > 0 && (
                        <SearchSection title="Категории">
                            {results.categories.map((cat) => (
                                <LinkItem
                                    key={cat.id}
                                    href={`/categories/${cat.slug}`}
                                    onClick={close}
                                >
                                    {cat.name}
                                </LinkItem>
                            ))}
                        </SearchSection>
                    )}

                    {/* Статьи */}
                    {results?.articles?.length > 0 && (
                        <SearchSection title="Статьи">
                            {results.articles.map((article) => (
                                <LinkItem
                                    key={article.id}
                                    href={`/articles/${article.slug}`}
                                    onClick={close}
                                >
                                    {article.title}
                                </LinkItem>
                            ))}
                        </SearchSection>
                    )}

                    {/* Новости */}
                    {results?.news?.length > 0 && (
                        <SearchSection title="Новости">
                            {results.news.map((item) => (
                                <LinkItem
                                    key={item.id}
                                    href={`/news/${item.slug}`}
                                    onClick={close}
                                >
                                    {item.title}
                                </LinkItem>
                            ))}
                        </SearchSection>
                    )}

                    {/* Кнопка «Все результаты» */}
                    {trimmed.length >= 2 && (
                        <Box px="3" py="2" borderTop="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                            <Flex
                                as={Link}
                                href={`/search?q=${encodeURIComponent(trimmed)}`}
                                onClick={close}
                                align="center"
                                justify="center"
                                gap="1"
                                py="1"
                                fontSize="sm"
                                fontWeight="500"
                                color="pecado.500"
                                _hover={{ textDecoration: 'underline' }}
                                cursor="pointer"
                            >
                                Все результаты
                                <LuArrowRight size={14} />
                            </Flex>
                        </Box>
                    )}
                </>
            );
        }

        // 6. Нет результатов (запрос >= 2 символов, но ничего не найдено)
        if (trimmed.length >= 2 && !loading) {
            return (
                <Flex direction="column" align="center" justify="center" py="8" gap="2">
                    <Box color="gray.300">
                        <LuSearch size={32} />
                    </Box>
                    <Text fontSize="sm" color="gray.400">Ничего не найдено</Text>
                </Flex>
            );
        }

        return null;
    };

    // ─── Мобильный режим: полноэкранный overlay ──────────────
    if (isSmall) {
        return (
            <Box
                position="fixed"
                top="0"
                left="0"
                right="0"
                bottom="0"
                bg="white"
                _dark={{ bg: 'gray.900' }}
                zIndex="60"
                display="flex"
                flexDirection="column"
            >
                {/* Шапка с инпутом */}
                <Flex align="center" gap="2" px="3" py="2" borderBottom="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                    <Box flex="1" as="form" onSubmit={handleSubmit}>
                        <Input
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Поиск товаров, брендов, категорий…"
                            size="sm"
                            borderRadius="lg"
                            bg="gray.50"
                            _dark={{ bg: 'gray.800' }}
                        />
                    </Box>
                    <IconButton
                        aria-label="Закрыть"
                        size="sm"
                        variant="ghost"
                        colorPalette="gray"
                        onClick={close}
                    >
                        <LuX size={20} />
                    </IconButton>
                </Flex>

                {/* Контент */}
                <Box flex="1" overflowY="auto">
                    {renderContent()}
                </Box>
            </Box>
        );
    }

    // ─── Десктопный режим: абсолютно позиционированный блок ──
    const content = renderContent();
    if (!content) return null;

    return (
        <Box
            position="absolute"
            top="100%"
            left="0"
            right="0"
            mt="1"
            bg="white"
            _dark={{ bg: 'gray.800', borderColor: 'gray.600' }}
            border="1px solid"
            borderColor="gray.200"
            borderRadius="lg"
            shadow="lg"
            maxH="384px"
            overflowY="auto"
            zIndex="50"
        >
            {content}
        </Box>
    );
}
