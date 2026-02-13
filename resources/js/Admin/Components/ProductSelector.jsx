import React, { useState, useEffect, useRef } from 'react';
import {
    Box,
    Input,
    HStack,
    VStack,
    Text,
    Image,
    Spinner,
    Badge,
    IconButton,
} from '@chakra-ui/react';
import { FaTimes } from 'react-icons/fa';
import axios from 'axios';

/**
 * ProductSelector - компонент для поиска и выбора товаров
 * 
 * @param {Array|Object} value - Выбранные товары (массив для mode='multi', null/object для mode='single')
 * @param {Function} onChange - Callback (новое значение)
 * @param {Function} onSelect - Callback (вызывается при выборе товара)
 * @param {string} error - Ошибка валидации
 * @param {string} mode - Режим работы: 'multi', 'single', 'search' (default: 'multi')
 */
export const ProductSelector = ({
    value = [],
    onChange,
    onSelect,
    error = null,
    mode = 'multi',
    searchRoute: customSearchRoute = null,
    searchParams: customSearchParams = {},
    renderItemActions = null,
}) => {
    const [query, setQuery] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const containerRef = useRef(null);

    // Click outside handler
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setShowSuggestions(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Search logic with debounce
    useEffect(() => {
        const timer = setTimeout(() => {
            if (query.trim().length >= 2) {
                searchProducts(query);
            } else {
                setSuggestions([]);
                setShowSuggestions(false);
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [query]);

    const searchProducts = async (searchQuery, autoSelect = false) => {
        setLoading(true);
        try {
            const searchRouteName = customSearchRoute || 'admin.products.search';
            const response = await axios.get(route(searchRouteName), {
                params: { query: searchQuery, ...customSearchParams }
            });

            // Фильтрация
            let filtered = response.data;
            if (mode === 'multi') {
                const selectedIds = Array.isArray(value) ? value.map(p => p.id) : [];
                filtered = response.data.filter(p => !selectedIds.includes(p.id));
            } else if (mode === 'single') {
                // For single/search, maybe we allow selecting same product again? or filtering?
                // Usually for single select we filter out the currently selected one if present
                if (value && value.id) {
                    filtered = response.data.filter(p => p.id !== value.id);
                }
            }

            if (autoSelect) {
                // 1. Exact Barcode Match
                const barcodeMatch = filtered.find(p =>
                    p.barcode === searchQuery ||
                    (p.barcodes && p.barcodes.includes(searchQuery))
                );

                if (barcodeMatch) {
                    handleSelect(barcodeMatch);
                    return; // Stop here, don't show suggestions
                }

                // 2. Exact SKU Match
                const skuMatch = filtered.find(p => p.sku === searchQuery);
                if (skuMatch) {
                    handleSelect(skuMatch);
                    return;
                }

                // 3. Single Result
                if (filtered.length === 1) {
                    handleSelect(filtered[0]);
                    return;
                }
            }

            setSuggestions(filtered);
            setShowSuggestions(true);
        } catch (error) {
            console.error('Search error:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (query.trim().length >= 1) {
                searchProducts(query, true);
            }
        }
    };

    const handleSelect = (product) => {
        if (onSelect) {
            onSelect(product);
        }

        if (mode === 'multi') {
            // Safe check for array
            const current = Array.isArray(value) ? value : [];
            onChange && onChange([...current, product]);
        } else if (mode === 'single') {
            onChange && onChange(product);
        } else if (mode === 'search') {
            // In search mode, we typically just notify via onSelect and clear
            onChange && onChange(product); // Optional
        }

        setQuery('');
        setSuggestions([]);
        setShowSuggestions(false);
    };

    const handleRemove = (productId) => {
        if (mode === 'multi') {
            onChange && onChange(value.filter(p => p.id !== productId));
        } else if (mode === 'single') {
            onChange && onChange(null);
        }
    };

    return (
        <Box ref={containerRef} width="100%">
            <Box position="relative" mb={mode === 'search' ? 0 : 4}>
                <Input
                    placeholder="Поиск товара по названию, артикулу, штрихкоду..."
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    onKeyDown={handleKeyDown}
                    borderColor={error ? "red.500" : "gray.200"}
                />

                {/* Выпадающий список */}
                {showSuggestions && (suggestions.length > 0 || loading) && (
                    <Box
                        position="absolute"
                        top="100%"
                        left={0}
                        right={0}
                        zIndex={1000}
                        bg="white"
                        boxShadow="lg"
                        borderRadius="md"
                        mt={1}
                        maxHeight="300px"
                        overflowY="auto"
                        borderWidth="1px"
                        borderColor="gray.200"
                    >
                        {loading && suggestions.length === 0 ? (
                            <Box p={4} textAlign="center">
                                <Spinner size="sm" />
                            </Box>
                        ) : (
                            <VStack align="stretch" gap={0}>
                                {suggestions.map((product) => (
                                    <HStack
                                        key={product.id}
                                        p={2}
                                        cursor="pointer"
                                        _hover={{ bg: "gray.50" }}
                                        onClick={() => handleSelect(product)}
                                        borderBottomWidth="1px"
                                        borderColor="gray.100"
                                        align="start"
                                    >
                                        <Image
                                            src={product.image_url || null}
                                            boxSize="50px"
                                            objectFit="cover"
                                            borderRadius="sm"
                                            fallbackSrc="https://via.placeholder.com/50"
                                        />
                                        <Box flex={1}>
                                            <Text fontSize="sm" fontWeight="medium" lineClamp={2}>
                                                {product.name}
                                            </Text>
                                            <HStack fontSize="xs" color="gray.500" flexWrap="wrap" gap={2}>
                                                <Badge size="sm" variant="outline" colorScheme="gray">
                                                    SKU: {product.sku || '—'}
                                                </Badge>
                                                {product.barcode && (
                                                    <Badge size="sm" variant="outline" colorScheme="blue">
                                                        {product.barcode}
                                                    </Badge>
                                                )}
                                                {product.price && (
                                                    <Text fontWeight="bold" color="green.600">
                                                        {new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(product.price)}
                                                    </Text>
                                                )}
                                            </HStack>
                                        </Box>
                                    </HStack>
                                ))}
                                {suggestions.length === 0 && !loading && (
                                    <Box p={4} textAlign="center" color="gray.500" fontSize="sm">
                                        Ничего не найдено
                                    </Box>
                                )}
                            </VStack>
                        )}
                    </Box>
                )}
                {error && <Text color="red.500" fontSize="sm" mt={1}>{error}</Text>}
            </Box>

            {/* Список выбранных товаров (Only for multi/single, not search) */}
            {mode !== 'search' && (
                <VStack align="stretch" gap={2}>
                    {mode === 'multi' && Array.isArray(value) && value.map((product) => (
                        <SelectedItem key={product.id} product={product} onRemove={() => handleRemove(product.id)} renderItemActions={renderItemActions} />
                    ))}
                    {mode === 'single' && value && (
                        <SelectedItem product={value} onRemove={() => handleRemove(value.id)} renderItemActions={renderItemActions} />
                    )}
                </VStack>
            )}
        </Box>
    );
};

const SelectedItem = ({ product, onRemove, renderItemActions }) => (
    <HStack
        p={3}
        borderWidth="1px"
        borderColor="gray.200"
        borderRadius="md"
        bg="white"
        justify="space-between"
    >
        <HStack gap={3} flex={1} minW={0}>
            <Image
                src={product.image_url || null}
                boxSize="40px"
                objectFit="cover"
                borderRadius="sm"
                fallbackSrc="https://via.placeholder.com/40"
            />
            <Box minW={0}>
                <Text fontSize="sm" fontWeight="medium" lineClamp={1}>
                    {product.name}
                </Text>
                <Text fontSize="xs" color="gray.500">
                    SKU: {product.sku}
                </Text>
            </Box>
        </HStack>
        <HStack gap={2} flexShrink={0}>
            {renderItemActions && renderItemActions(product)}
            <IconButton
                aria-label="Удалить"
                size="sm"
                variant="ghost"
                colorPalette="red"
                onClick={onRemove}
            >
                <FaTimes />
            </IconButton>
        </HStack>
    </HStack>
);

