import { useMemo, useState } from 'react';
import { Box, Flex, Text, Input, IconButton, Spinner, Badge, Portal } from '@chakra-ui/react';
import { Link, router } from '@inertiajs/react';
import {
    LuX, LuClock3, LuSearch, LuArrowRight, LuHeart, LuCheck, LuCircleX,
    LuImageOff, LuScanBarcode,
} from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import CartQuantityControl from '@/components/product/CartQuantityControl';
import { useProductHelpers } from '@/hooks/useProductHelpers';
import SearchSection from './SearchSection';
import BarcodeSearchScanner from './BarcodeSearchScanner';

/**
 * Горизонтальная карточка товара для дропдауна поиска.
 * По смыслу — компактный ProductListItem: миниатюра + артикул/бренд + название +
 * бейджи + цена и кнопка «В корзину» (CartQuantityControl из grid-карточки).
 */
function ProductItem({ product, onClick }) {
    const {
        user, isFav, toggleFavorite, formatPrice,
        hasSale, isInStock, isPreorder, brandName,
        price, salePrice, discountPct,
    } = useProductHelpers(product);

    const imageUrl = product.thumbnail || product.image_url || product.thumb_url || product.main_image;
    const [imageBroken, setImageBroken] = useState(false);
    const showPlaceholder = !imageUrl || imageBroken;

    return (
        <Flex
            align="flex-start"
            gap="2.5"
            px="3"
            py="1.5"
            borderBottom="1px solid"
            borderColor="border.muted"
            _hover={{ bg: 'gray.50' }}
            _dark={{ _hover: { bg: 'gray.700' } }}
            transition="background 0.15s"
        >
            {/* Миниатюра — пропорция 2:3 */}
            <Box
                as={Link}
                href={`/products/${product.slug}`}
                onClick={onClick}
                w={{ base: '48px', md: '56px' }}
                h={{ base: '72px', md: '84px' }}
                flexShrink="0"
                borderRadius="md"
                overflow="hidden"
                bg="gray.100"
                _dark={{ bg: 'gray.700' }}
                display="flex"
                alignItems="center"
                justifyContent="center"
            >
                {showPlaceholder ? (
                    <LuImageOff size={18} color="var(--chakra-colors-gray-400)" />
                ) : (
                    <Box
                        as="img"
                        src={imageUrl}
                        alt=""
                        loading="lazy"
                        w="100%"
                        h="100%"
                        objectFit="cover"
                        onError={() => setImageBroken(true)}
                    />
                )}
            </Box>

            {/* Информация */}
            <Flex flex="1" minW="0" direction="column" gap="0.5">
                {/* Артикул / бренд / бейджи + сердечко */}
                <Flex align="center" justify="space-between" gap="2">
                    <Flex flex="1" minW="0" align="center" gap="1.5" wrap="wrap" lineHeight="1">
                        {product.sku && (
                            <Text fontSize="xs" fontWeight="700" color="gray.500">
                                {product.sku}
                            </Text>
                        )}
                        {brandName && (
                            <>
                                {product.sku && <Text fontSize="xs" color="gray.300">/</Text>}
                                {product.brand_slug ? (
                                    <Text
                                        as={Link}
                                        href={`/brands/${product.brand_slug}`}
                                        onClick={onClick}
                                        fontSize="xs"
                                        color="gray.500"
                                        textTransform="capitalize"
                                        _hover={{ textDecoration: 'underline' }}
                                        lineClamp="1"
                                    >
                                        {brandName}
                                    </Text>
                                ) : (
                                    <Text
                                        fontSize="xs"
                                        color="gray.500"
                                        textTransform="capitalize"
                                        lineClamp="1"
                                    >
                                        {brandName}
                                    </Text>
                                )}
                            </>
                        )}
                        {product.is_bestseller && (
                            <Badge colorPalette="orange" fontSize="2xs" fontWeight="700" borderRadius="md" px="1">
                                Хит
                            </Badge>
                        )}
                        {product.is_new && (
                            <Badge colorPalette="green" fontSize="2xs" fontWeight="700" borderRadius="md" px="1">
                                Новинка
                            </Badge>
                        )}
                        {hasSale && discountPct && (
                            <Badge colorPalette="red" fontSize="2xs" fontWeight="700" borderRadius="md" px="1">
                                −{Math.round(discountPct)}%
                            </Badge>
                        )}
                        {product.is_marked && (
                            <Badge colorPalette="yellow" fontSize="2xs" fontWeight="700" borderRadius="md" px="1">
                                Маркировка
                            </Badge>
                        )}
                    </Flex>
                    {user && (
                        <IconButton
                            aria-label="В избранное"
                            variant="ghost"
                            size="xs"
                            borderRadius="sm"
                            onClick={toggleFavorite}
                            color={isFav ? 'red.500' : 'gray.400'}
                            _hover={{ color: 'red.500' }}
                            minW="5"
                            h="5"
                            flexShrink="0"
                        >
                            <LuHeart size={13} fill={isFav ? 'currentColor' : 'none'} />
                        </IconButton>
                    )}
                </Flex>

                {/* Название */}
                <Text
                    as={Link}
                    href={`/products/${product.slug}`}
                    onClick={onClick}
                    fontSize="sm"
                    fontWeight="500"
                    lineHeight="1.25"
                    display="-webkit-box"
                    css={{
                        WebkitLineClamp: 2,
                        WebkitBoxOrient: 'vertical',
                        overflow: 'hidden',
                    }}
                    _hover={{ textDecoration: 'underline' }}
                >
                    {product.name}
                </Text>

                {/* Нижняя строка: статус + цена + корзина — только для авторизованных */}
                {user && (
                    <Flex
                        align="center"
                        justify="space-between"
                        gap="2"
                        wrap="wrap"
                    >
                        {/* Статус наличия */}
                        <Flex align="center" gap="1" fontSize="xs" fontWeight="500" flexShrink="0">
                            {isInStock ? (
                                <>
                                    <LuCheck size={12} color="var(--chakra-colors-green-600)" />
                                    <Text color="green.600" lineClamp="1">В наличии</Text>
                                </>
                            ) : isPreorder ? (
                                <>
                                    <LuClock3 size={12} color="var(--chakra-colors-orange-500)" />
                                    <Text color="orange.500" lineClamp="1">Предзаказ</Text>
                                </>
                            ) : (
                                <>
                                    <LuCircleX size={12} color="var(--chakra-colors-red-600)" />
                                    <Text color="red.600" lineClamp="1">Нет в наличии</Text>
                                </>
                            )}
                        </Flex>

                        {/* Цена + компактный контрол корзины */}
                        <Flex align="center" gap="2" flexShrink="0">
                            {price != null && (
                                <Flex align="baseline" gap="1" lineHeight="1">
                                    {hasSale && (
                                        <Text fontSize="2xs" color="gray.400" textDecoration="line-through">
                                            {formatPrice(price)}
                                        </Text>
                                    )}
                                    <Text
                                        fontSize="sm"
                                        fontWeight="700"
                                        color={hasSale ? 'red.600' : undefined}
                                    >
                                        {formatPrice(hasSale ? salePrice : price)}
                                    </Text>
                                </Flex>
                            )}
                            {(isInStock || isPreorder) && (hasSale ? salePrice : price) > 0 && (
                                <Box onClick={(e) => { e.preventDefault(); e.stopPropagation(); }}>
                                    <CartQuantityControl
                                        productId={product.id}
                                        stockQuantity={product.stock_quantity ?? 0}
                                        preorderQuantity={product.preorder_quantity ?? 0}
                                        size="xs"
                                        variant="compact"
                                    />
                                </Box>
                            )}
                        </Flex>
                    </Flex>
                )}
            </Flex>
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
    // Фильтрация товаров по наличию (клиентская).
    // Предзаказы НЕ скрываются под чекбоксом — их учёт идёт по preorder_quantity,
    // которое отдаёт ProductQueryService (поля is_preorder в payload нет).
    const filteredProducts = useMemo(() => {
        const products = results?.products ?? [];
        if (includeUnavailable) return products;
        return products.filter((p) => {
            const qty = p.available_quantity ?? p.stock_quantity ?? 0;
            const preorderQty = p.preorder_quantity ?? 0;
            return qty > 0 || preorderQty > 0;
        });
    }, [results?.products, includeUnavailable]);

    const [scannerOpen, setScannerOpen] = useState(false);

    const close = () => setOpen(false);

    const handleHistorySelect = (q) => {
        setQuery(q);
    };

    const handleSubmit = (e) => {
        e?.preventDefault?.();
        submitSearch();
    };

    const handleBarcodeScan = (text) => {
        const code = String(text || '').trim();
        if (!code) return;
        setScannerOpen(false);
        setQuery(code);
        setOpen(false);
        router.visit(`/search?q=${encodeURIComponent(code)}`);
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
                        <Box px="3" py="2" borderTop="1px solid" borderColor="border.muted">
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
    // Portal вынимает overlay из родителя с `transform`/`will-change` (UserHeader),
    // который иначе становится containing block для position: fixed и схлопывает оверлей.
    if (isSmall) {
        return (
            <Portal>
                <Box
                    position="fixed"
                    top="0"
                    left="0"
                    right="0"
                    bottom="0"
                    bg="bg"
                    _dark={{ bg: 'gray.900' }}
                    zIndex="60"
                    display="flex"
                    flexDirection="column"
                >
                    {/* Шапка с инпутом */}
                    <Flex align="center" gap="2" px="3" py="2" borderBottom="1px solid" borderColor="border.muted">
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
                            aria-label="Поиск по штрихкоду"
                            size="sm"
                            variant="ghost"
                            colorPalette="gray"
                            onClick={() => setScannerOpen(true)}
                        >
                            <LuScanBarcode size={20} />
                        </IconButton>
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
                    <Box flex="1" overflowY="auto" overflowX="hidden">
                        {renderContent()}
                    </Box>

                    <BarcodeSearchScanner
                        open={scannerOpen}
                        onScan={handleBarcodeScan}
                        onClose={() => setScannerOpen(false)}
                    />
                </Box>
            </Portal>
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
            bg="bg"

            border="1px solid"
            borderColor="border"
            borderRadius="lg"
            shadow="lg"
            maxH="min(640px, calc(100vh - 120px))"
            overflowY="auto"
            overflowX="hidden"
            zIndex="50"
        >
            {content}
        </Box>
    );
}
