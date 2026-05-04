import { useState, useCallback, useRef, useEffect } from 'react';
import {
    Box, Flex, VStack, HStack, Text, Card, Badge, Button,
    IconButton, Table, Image, Input, Dialog, Portal,
} from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import {
    LuArrowLeft, LuTrash2,
    LuPackage, LuShoppingCart, LuEraser,
    LuPencil, LuCheck, LuX, LuSearch,
} from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import QuantityControl from '@/components/common/QuantityControl';
import axios from 'axios';

export default function Show({ cart, cartDetails }) {
    const [items, setItems] = useState(cartDetails?.items || []);
    const [updatingItem, setUpdatingItem] = useState(null);

    // Dialogs
    const [removeItem, setRemoveItem] = useState(null);
    const [showClearDialog, setShowClearDialog] = useState(false);

    // Rename
    const [isRenaming, setIsRenaming] = useState(false);
    const [cartName, setCartName] = useState(cart.name || '');

    // Product search
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState([]);
    const [isSearching, setIsSearching] = useState(false);
    const [showResults, setShowResults] = useState(false);
    const searchRef = useRef(null);
    const debounceRef = useRef(null);

    const formatPrice = (val) =>
        new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(val || 0);

    const totalRegular = items.reduce((s, i) => s + (i.total_amount_regular || 0), 0);
    const totalDiscounted = items.reduce((s, i) => s + (i.total_amount_discounted || 0), 0);
    const totalQuantity = items.reduce((s, i) => s + i.quantity, 0);
    const hasDiscount = totalRegular > totalDiscounted && totalDiscounted > 0;

    // === Rename ===
    const saveRename = async () => {
        if (!cartName.trim()) return;
        try {
            await axios.patch(`/cabinet/carts/${cart.id}/rename`, { name: cartName.trim() });
            setIsRenaming(false);
            toaster.create({ title: 'Название обновлено', type: 'success' });
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка', type: 'error' });
        }
    };

    // === Quantity ===
    const reloadCartDetails = () => new Promise((resolve) => {
        router.reload({ only: ['cartDetails'], onFinish: () => resolve() });
    });

    // Debounced PATCH для каждой строки корзины: long-press / быстрые клики
    // больше не плодят параллельные axios.patch + router.reload, а сворачиваются
    // в один запрос с финальным qty. Предыдущий in-flight запрос отменяется
    // через AbortController, чтобы reload отрабатывал только на самом свежем.
    const UPDATE_DEBOUNCE_MS = 450;
    const updateTimers = useRef({});
    const updateAborters = useRef({});
    const optimisticQty = useRef({});

    useEffect(() => {
        return () => {
            Object.values(updateTimers.current).forEach(clearTimeout);
            Object.values(updateAborters.current).forEach((c) => c.abort());
            updateTimers.current = {};
            updateAborters.current = {};
            optimisticQty.current = {};
        };
    }, []);

    const handleUpdateQuantity = useCallback((item, newQty) => {
        if (newQty < 1) return;

        // Optimistic update в локальном items — счётчик в инпуте не отстаёт
        // от ввода, цветной бейдж/итоги пересчитываются мгновенно.
        setItems((prev) => prev.map((i) => (i.id === item.id ? { ...i, quantity: newQty } : i)));
        optimisticQty.current[item.id] = newQty;

        if (updateTimers.current[item.id]) {
            clearTimeout(updateTimers.current[item.id]);
        }

        updateTimers.current[item.id] = setTimeout(async () => {
            delete updateTimers.current[item.id];

            if (updateAborters.current[item.id]) {
                updateAborters.current[item.id].abort();
            }
            const ctrl = new AbortController();
            updateAborters.current[item.id] = ctrl;
            const sentQty = optimisticQty.current[item.id];

            setUpdatingItem(item.id);
            try {
                await axios.patch(
                    `/api/cart/items/${item.id}`,
                    { quantity: sentQty },
                    { signal: ctrl.signal },
                );
                if (updateAborters.current[item.id] === ctrl) {
                    // Чистим optimistic-кеш только если за время запроса юзер
                    // не наклацал ещё одно значение. Иначе reload-merge должен
                    // оставить актуальное локальное qty до следующего POST.
                    if (optimisticQty.current[item.id] === sentQty) {
                        delete optimisticQty.current[item.id];
                    }
                    await reloadCartDetails();
                }
            } catch (e) {
                if (axios.isCancel?.(e) || e.name === 'CanceledError' || e.code === 'ERR_CANCELED') return;
                toaster.create({ title: 'Ошибка обновления', type: 'error' });
                if (optimisticQty.current[item.id] === sentQty) {
                    delete optimisticQty.current[item.id];
                }
                await reloadCartDetails();
            } finally {
                if (updateAborters.current[item.id] === ctrl) {
                    delete updateAborters.current[item.id];
                    setUpdatingItem(null);
                }
            }
        }, UPDATE_DEBOUNCE_MS);
    }, []);

    // === Remove item ===
    const confirmRemoveItem = async () => {
        if (!removeItem) return;
        try {
            await axios.delete(`/api/cart/items/${removeItem.id}`);
            setItems(prev => prev.filter(i => i.id !== removeItem.id));
            toaster.create({ title: 'Товар удалён', type: 'success' });
        } catch {
            toaster.create({ title: 'Ошибка удаления', type: 'error' });
        } finally {
            setRemoveItem(null);
        }
    };

    // === Clear cart ===
    const confirmClearCart = async () => {
        try {
            await axios.delete('/api/cart/clear');
            setItems([]);
            setShowClearDialog(false);
            toaster.create({ title: 'Корзина очищена', type: 'success' });
        } catch {
            toaster.create({ title: 'Ошибка очистки', type: 'error' });
        }
    };

    // === Product search ===
    const doSearch = useCallback(async (q) => {
        if (q.length < 2) { setSearchResults([]); setShowResults(false); return null; }
        setIsSearching(true);
        try {
            const { data } = await axios.get('/cabinet/carts/search-products', { params: { query: q } });
            setSearchResults(data);
            setShowResults(true);
            return data;
        } catch { /* ignore */
            return null;
        } finally {
            setIsSearching(false);
        }
    }, []);

    const handleSearchChange = (e) => {
        const q = e.target.value;
        setSearchQuery(q);
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(async () => {
            const data = await doSearch(q);
            // Авто-вставка по штрихкоду: ровно 13/12/8 цифр и точное совпадение
            if (
                data
                && data.length === 1
                && data[0].match_source === 'barcode_exact'
                && /^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/.test(q.trim())
            ) {
                handleAddProduct(data[0]);
            }
        }, 300);
    };

    const handleAddProduct = async (product) => {
        setShowResults(false);
        setSearchQuery('');
        try {
            const { data } = await axios.post('/api/cart/add-product', {
                product_id: product.id,
                quantity: 1,
            });
            const added = (data.instock || 0) + (data.preorder || 0);
            if (added > 0) {
                toaster.create({ title: `"${product.name}" добавлен в корзину`, type: 'success' });
                router.reload();
            } else {
                toaster.create({ title: `"${product.name}" — нет в наличии`, type: 'warning' });
            }
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка добавления', type: 'error' });
        }
    };

    // Close search results on outside click
    useEffect(() => {
        const handler = (e) => {
            if (searchRef.current && !searchRef.current.contains(e.target)) setShowResults(false);
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    // Sync with server data on reload. Если для какого-то item у нас есть
    // pending optimistic qty (юзер кликнул, debounce ещё не сработал или
    // ответ ещё не пришёл) — сохраняем локальное значение, чтобы инпут не
    // дёргался назад на старое серверное число.
    useEffect(() => {
        if (!cartDetails?.items) return;
        setItems(cartDetails.items.map((srv) => {
            const pending = optimisticQty.current[srv.id];
            return pending != null ? { ...srv, quantity: pending } : srv;
        }));
    }, [cartDetails]);

    // === Item type badge (instock/preorder) ===
    const ItemTypeBadge = ({ type }) => {
        if (type === 'instock') {
            return <Badge colorPalette="green" size="sm" variant="subtle">В наличии</Badge>;
        }
        if (type === 'preorder') {
            return <Badge colorPalette="orange" size="sm" variant="subtle">Под заказ</Badge>;
        }
        return null;
    };

    // === Render item price cell ===
    const PriceCell = ({ item }) => {
        const hasItemDiscount = item.price_regular > item.price_discounted && item.price_discounted > 0;
        if (!item.product) return <Text>—</Text>;
        if (hasItemDiscount) {
            return (
                <VStack gap="0" align="end">
                    <Text fontSize="xs" color="gray.400" textDecoration="line-through">{formatPrice(item.price_regular)}</Text>
                    <Text fontSize="sm" fontWeight="700" color="red.500">{formatPrice(item.price_discounted)}</Text>
                </VStack>
            );
        }
        return <Text fontSize="sm">{formatPrice(item.price_regular)}</Text>;
    };

    const TotalCell = ({ item }) => {
        const hasItemDiscount = item.total_amount_regular > item.total_amount_discounted && item.total_amount_discounted > 0;
        if (!item.product) return <Text>—</Text>;
        if (hasItemDiscount) {
            return (
                <VStack gap="0" align="end">
                    <Text fontSize="xs" color="gray.400" textDecoration="line-through">{formatPrice(item.total_amount_regular)}</Text>
                    <Text fontSize="sm" fontWeight="800" color="red.500">{formatPrice(item.total_amount_discounted)}</Text>
                </VStack>
            );
        }
        return <Text fontSize="sm" fontWeight="700">{formatPrice(item.total_amount_regular)}</Text>;
    };

    // === Title ===
    const titleContent = isRenaming ? (
        <HStack gap="2">
            <Input
                size="sm" w="250px"
                value={cartName}
                onChange={(e) => setCartName(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && saveRename()}
                autoFocus
            />
            <IconButton size="sm" variant="ghost" colorPalette="green" onClick={saveRename}><LuCheck /></IconButton>
            <IconButton size="sm" variant="ghost" onClick={() => setIsRenaming(false)}><LuX /></IconButton>
        </HStack>
    ) : (
        <HStack gap="2">
            <Text as="span">{cartName || `Корзина #${cart.id}`}</Text>
            <IconButton size="sm" variant="ghost" color="gray.400" onClick={() => setIsRenaming(true)} aria-label="Переименовать">
                <LuPencil />
            </IconButton>
            {cart.is_active && <Badge colorPalette="green" variant="subtle">Активная</Badge>}
        </HStack>
    );

    return (
        <CabinetLayout title={titleContent}>
            <Head title={`${cartName || 'Корзина'} — Pecado`} />

            <Flex justify="space-between" align="center" mb="4" flexWrap="wrap" gap="2">
                <Button variant="ghost" size="sm" onClick={() => router.visit('/cabinet/carts')}>
                    <LuArrowLeft /> Назад к списку
                </Button>
                {items.length > 0 && (
                    <Button size="sm" variant="outline" colorPalette="red" onClick={() => setShowClearDialog(true)}>
                        <LuEraser /> Очистить
                    </Button>
                )}
            </Flex>

            {/* Product Search */}
            <Card.Root bg="bg" borderRadius="xl" mb="4" border="1px solid" borderColor="border.muted">
                <Card.Body p="4">
                    <Text fontWeight="600" mb="2" fontSize="sm">Добавить товар</Text>
                    <Box ref={searchRef} position="relative">
                        <HStack>
                            <Box position="relative" flex="1">
                                <Input
                                    placeholder="Название, бренд, артикул или штрихкод…"
                                    value={searchQuery}
                                    onChange={handleSearchChange}
                                    onFocus={() => searchResults.length > 0 && setShowResults(true)}
                                    size="sm"
                                />
                            </Box>
                            <IconButton size="sm" variant="ghost" aria-label="Поиск" disabled>
                                <LuSearch />
                            </IconButton>
                        </HStack>

                        {/* Search dropdown */}
                        {showResults && searchResults.length > 0 && (
                            <Box
                                position="absolute" top="100%" left="0" right="0" zIndex="10"
                                bg="bg" _dark={{ bg: 'gray.800' }}
                                border="1px solid" borderColor="border"
                                borderRadius="md" shadow="lg" mt="1"
                                maxH="350px" overflowY="auto"
                            >
                                {searchResults.map((p) => {
                                    const available = Number(p.stock_available || 0);
                                    const preorder = Number(p.stock_preorder || 0);
                                    const outOfStock = available <= 0 && preorder <= 0;
                                    let stockBadge;
                                    if (available > 0) {
                                        stockBadge = (
                                            <Badge size="sm" colorPalette="green" variant="subtle">
                                                В наличии: {available}
                                            </Badge>
                                        );
                                    } else if (preorder > 0) {
                                        stockBadge = (
                                            <Badge size="sm" colorPalette="orange" variant="subtle">
                                                Под заказ: {preorder}
                                            </Badge>
                                        );
                                    } else {
                                        stockBadge = (
                                            <Badge size="sm" colorPalette="red" variant="subtle">
                                                Нет в наличии
                                            </Badge>
                                        );
                                    }
                                    return (
                                        <Flex
                                            key={p.id}
                                            p="2" gap="3" align="center"
                                            cursor={outOfStock ? 'not-allowed' : 'pointer'}
                                            opacity={outOfStock ? 0.55 : 1}
                                            _hover={outOfStock ? undefined : { bg: 'gray.50/50', _dark: { bg: 'gray.700/50' } }}
                                            onClick={() => {
                                                if (outOfStock) {
                                                    toaster.create({ title: `"${p.name}" — нет в наличии`, type: 'warning' });
                                                    return;
                                                }
                                                handleAddProduct(p);
                                            }}
                                        >
                                            {p.image_url ? (
                                                <Image src={p.image_url} alt={p.name} boxSize="40px" objectFit="cover" borderRadius="md" flexShrink="0" />
                                            ) : (
                                                <Flex boxSize="40px" bg="gray.100" borderRadius="md" align="center" justify="center" flexShrink="0">
                                                    <LuPackage size={16} />
                                                </Flex>
                                            )}
                                            <Box flex="1" minW="0">
                                                <Text fontSize="sm" fontWeight="500" noOfLines={1}>{p.name}</Text>
                                                <HStack gap="2" wrap="wrap">
                                                    {p.sku && <Text fontSize="xs" color="gray.400">Арт: {p.sku}</Text>}
                                                    {p.brand_name && <Badge size="sm" colorPalette="purple" variant="subtle">{p.brand_name}</Badge>}
                                                    {stockBadge}
                                                    {p.purchased_count > 0 && (
                                                        <Badge size="sm" colorPalette="green" variant="subtle">
                                                            ✓ покупали {p.purchased_count}×
                                                        </Badge>
                                                    )}
                                                    {p.in_favorites && (
                                                        <Badge size="sm" colorPalette="orange" variant="subtle">
                                                            ★ в избранном
                                                        </Badge>
                                                    )}
                                                    {p.match_source === 'barcode_exact' && (
                                                        <Badge size="sm" colorPalette="blue" variant="subtle">
                                                            Штрихкод
                                                        </Badge>
                                                    )}
                                                </HStack>
                                            </Box>
                                            <Text fontSize="sm" fontWeight="600" flexShrink="0">{formatPrice(p.base_price)}</Text>
                                        </Flex>
                                    );
                                })}
                            </Box>
                        )}
                        {showResults && searchResults.length === 0 && searchQuery.length >= 2 && !isSearching && (
                            <Box
                                position="absolute" top="100%" left="0" right="0" zIndex="10"
                                bg="bg" _dark={{ bg: 'gray.800' }}
                                border="1px solid" borderColor="border"
                                borderRadius="md" shadow="lg" mt="1" p="3"
                                textAlign="center"
                            >
                                <Text fontSize="sm" color="gray.400">Ничего не найдено</Text>
                            </Box>
                        )}
                    </Box>
                </Card.Body>
            </Card.Root>

            {/* Stats */}
            <HStack gap="4" mb="4" flexWrap="wrap">
                <Card.Root bg="bg" borderRadius="lg" flex="1" minW="120px">
                    <Card.Body p="3" textAlign="center">
                        <Text fontSize="xs" color="gray.400">Позиций</Text>
                        <Text fontSize="xl" fontWeight="800">{items.length}</Text>
                    </Card.Body>
                </Card.Root>
                <Card.Root bg="bg" borderRadius="lg" flex="1" minW="120px">
                    <Card.Body p="3" textAlign="center">
                        <Text fontSize="xs" color="gray.400">Единиц</Text>
                        <Text fontSize="xl" fontWeight="800">{totalQuantity}</Text>
                    </Card.Body>
                </Card.Root>
                <Card.Root bg="bg" borderRadius="lg" flex="1" minW="120px">
                    <Card.Body p="3" textAlign="center">
                        <Text fontSize="xs" color="gray.400">Итого</Text>
                        {hasDiscount ? (
                            <>
                                <Text fontSize="xs" color="gray.400" textDecoration="line-through">{formatPrice(totalRegular)}</Text>
                                <Text fontSize="xl" fontWeight="800" color="red.500">{formatPrice(totalDiscounted)}</Text>
                            </>
                        ) : (
                            <Text fontSize="xl" fontWeight="800" color="green.600">{formatPrice(totalRegular)}</Text>
                        )}
                    </Card.Body>
                </Card.Root>
            </HStack>

            {/* Items */}
            <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted" overflow="hidden">
                {items.length === 0 ? (
                    <Card.Body p="8" textAlign="center">
                        <VStack gap="3">
                            <LuPackage size={48} style={{ opacity: 0.2 }} />
                            <Text color="gray.400">Корзина пуста</Text>
                            <Text color="gray.400" fontSize="sm">Используйте поиск выше, чтобы добавить товары</Text>
                        </VStack>
                    </Card.Body>
                ) : (
                    <>
                        {/* Desktop Table */}
                        <Box display={{ base: 'none', md: 'block' }}>
                            <Table.Root bg="bg" size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader w="60px">Фото</Table.ColumnHeader>
                                        <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Цена</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="center" w="160px">Кол-во</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                        <Table.ColumnHeader w="60px" />
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {items.map((item) => (
                                        <Table.Row key={item.id}>
                                            <Table.Cell>
                                                {item.product?.thumbnail_url || item.product?.main_image_url ? (
                                                    <Image
                                                        src={item.product.thumbnail_url || item.product.main_image_url}
                                                        alt={item.product.name}
                                                        boxSize="50px"
                                                        objectFit="cover"
                                                        borderRadius="md"
                                                    />
                                                ) : (
                                                    <Flex boxSize="50px" bg="gray.100" borderRadius="md" align="center" justify="center">
                                                        <LuPackage size={18} />
                                                    </Flex>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                {item.product ? (
                                                    <VStack align="start" gap="1">
                                                        <Text fontWeight="600" fontSize="sm">{item.product.name}</Text>
                                                        {item.product.sku && (
                                                            <Text fontSize="xs" color="gray.400">Арт: {item.product.sku}</Text>
                                                        )}
                                                        <HStack gap="1" wrap="wrap">
                                                            <ItemTypeBadge type={item.item_type} />
                                                            {item.product.brand?.name && (
                                                                <Badge colorPalette="purple" size="sm" variant="subtle">{item.product.brand.name}</Badge>
                                                            )}
                                                        </HStack>
                                                    </VStack>
                                                ) : (
                                                    <Text color="red.500">Товар удалён</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <PriceCell item={item} />
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">
                                                <Flex justify="center">
                                                    <QuantityControl
                                                        value={item.quantity}
                                                        onChange={(v) => handleUpdateQuantity(item, v)}
                                                        min={1}
                                                        size="md"
                                                        disabled={updatingItem === item.id}
                                                    />
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <TotalCell item={item} />
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">
                                                <IconButton
                                                    size="xs" variant="ghost" colorPalette="red"
                                                    aria-label="Удалить"
                                                    onClick={() => setRemoveItem(item)}
                                                >
                                                    <LuTrash2 />
                                                </IconButton>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>

                        {/* Mobile Cards */}
                        <VStack display={{ base: 'flex', md: 'none' }} gap="0" align="stretch" separator={<Box borderTop="1px solid" borderColor="border.muted" />}>
                            {items.map((item) => (
                                <Box key={item.id} p="3">
                                    <Flex gap="3">
                                        {item.product?.thumbnail_url || item.product?.main_image_url ? (
                                            <Image
                                                src={item.product.thumbnail_url || item.product.main_image_url}
                                                alt={item.product.name}
                                                boxSize="60px" objectFit="cover" borderRadius="md" flexShrink="0"
                                            />
                                        ) : (
                                            <Flex boxSize="60px" bg="gray.100" borderRadius="md" align="center" justify="center" flexShrink="0">
                                                <LuPackage size={20} />
                                            </Flex>
                                        )}
                                        <Box flex="1" minW="0">
                                            <Text fontWeight="600" fontSize="sm" noOfLines={2}>{item.product?.name || 'Товар удалён'}</Text>
                                            <HStack gap="1" wrap="wrap" mt="1">
                                                <ItemTypeBadge type={item.item_type} />
                                                {item.product?.brand?.name && (
                                                    <Badge colorPalette="purple" size="sm" variant="subtle">{item.product.brand.name}</Badge>
                                                )}
                                            </HStack>
                                            <Flex justify="space-between" align="center" mt="2">
                                                <Box>
                                                    {item.total_amount_regular > item.total_amount_discounted && item.total_amount_discounted > 0 ? (
                                                        <>
                                                            <Text fontSize="xs" color="gray.400" textDecoration="line-through">{formatPrice(item.total_amount_regular)}</Text>
                                                            <Text fontSize="sm" fontWeight="700" color="red.500">{formatPrice(item.total_amount_discounted)}</Text>
                                                        </>
                                                    ) : (
                                                        <Text fontSize="sm" fontWeight="700" color="green.600">{formatPrice(item.total_amount_regular)}</Text>
                                                    )}
                                                </Box>
                                                <HStack gap="2">
                                                    <QuantityControl
                                                        value={item.quantity}
                                                        onChange={(v) => handleUpdateQuantity(item, v)}
                                                        min={1}
                                                        size="sm"
                                                        disabled={updatingItem === item.id}
                                                    />
                                                    <IconButton size="xs" variant="ghost" colorPalette="red" onClick={() => setRemoveItem(item)}>
                                                        <LuTrash2 />
                                                    </IconButton>
                                                </HStack>
                                            </Flex>
                                        </Box>
                                    </Flex>
                                </Box>
                            ))}
                        </VStack>

                        {/* Totals Footer */}
                        <Box borderTop="1px solid" borderColor="border" p="4">
                            <Flex justify="space-between" align="center">
                                <HStack gap="6">
                                    <Box>
                                        <Text fontSize="xs" color="gray.400">Позиций</Text>
                                        <Text fontWeight="700">{items.length}</Text>
                                    </Box>
                                    <Box>
                                        <Text fontSize="xs" color="gray.400">Единиц</Text>
                                        <Text fontWeight="700">{totalQuantity}</Text>
                                    </Box>
                                </HStack>
                                <Box textAlign="right">
                                    <Text fontSize="xs" color="gray.400">Итого</Text>
                                    {hasDiscount ? (
                                        <>
                                            <Text fontSize="sm" color="gray.400" textDecoration="line-through">{formatPrice(totalRegular)}</Text>
                                            <Text fontWeight="800" fontSize="xl" color="red.500">{formatPrice(totalDiscounted)}</Text>
                                        </>
                                    ) : (
                                        <Text fontWeight="800" fontSize="xl" color="green.600">{formatPrice(totalRegular)}</Text>
                                    )}
                                </Box>
                            </Flex>
                        </Box>
                    </>
                )}
            </Card.Root>

            {/* Footer with dates */}
            <Box textAlign="center" color="gray.400" fontSize="xs" mt="4">
                <Text>Создана: {cart.created_at} · Обновлена: {cart.updated_at}</Text>
            </Box>

            {/* Remove Item Dialog */}
            <Dialog.Root open={!!removeItem} onOpenChange={({ open }) => !open && setRemoveItem(null)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Удаление товара</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text>Удалить «{removeItem?.product?.name}» из корзины?</Text>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setRemoveItem(null)}>Отмена</Button>
                                <Button colorPalette="red" onClick={confirmRemoveItem}>Удалить</Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>

            {/* Clear Cart Dialog */}
            <Dialog.Root open={showClearDialog} onOpenChange={({ open }) => !open && setShowClearDialog(false)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Очистка корзины</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text>Очистить корзину? Все товары будут удалены.</Text>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setShowClearDialog(false)}>Отмена</Button>
                                <Button colorPalette="red" onClick={confirmClearCart}>Очистить</Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </CabinetLayout>
    );
}
