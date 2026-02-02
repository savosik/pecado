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
 * @param {Array} value - Массив выбранных товаров (объекты {id, name, sku, image_url})
 * @param {Function} onChange - Callback (новое значение массива)
 * @param {string} error - Ошибка валидации
 */
export const ProductSelector = ({
    value = [],
    onChange,
    error = null,
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

    const searchProducts = async (searchQuery) => {
        setLoading(true);
        try {
            const response = await axios.get(route('admin.products.search'), {
                params: { query: searchQuery }
            });
            // Фильтруем уже выбранные товары из подсказок
            const selectedIds = value.map(p => p.id);
            const filtered = response.data.filter(p => !selectedIds.includes(p.id));
            setSuggestions(filtered);
            setShowSuggestions(true);
        } catch (error) {
            console.error('Search error:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleSelect = (product) => {
        onChange([...value, product]);
        setQuery('');
        setSuggestions([]);
        setShowSuggestions(false);
    };

    const handleRemove = (productId) => {
        onChange(value.filter(p => p.id !== productId));
    };

    return (
        <Box ref={containerRef} width="100%">
            <Box position="relative" mb={4}>
                <Input
                    placeholder="Поиск товара по названию, артикулу..."
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
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
                                    >
                                        <Image
                                            src={product.image_url || null}
                                            boxSize="40px"
                                            objectFit="cover"
                                            borderRadius="sm"
                                            fallbackSrc="https://via.placeholder.com/40"
                                        />
                                        <Box flex={1}>
                                            <Text fontSize="sm" fontWeight="medium" lineClamp={1}>
                                                {product.name}
                                            </Text>
                                            <HStack fontSize="xs" color="gray.500">
                                                <Text>SKU: {product.sku}</Text>
                                                {product.price && (
                                                    <Text>• {new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(product.price)}</Text>
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

            {/* Список выбранных товаров */}
            {value.length > 0 && (
                <VStack align="stretch" gap={2}>
                    {value.map((product) => (
                        <HStack
                            key={product.id}
                            p={3}
                            borderWidth="1px"
                            borderColor="gray.200"
                            borderRadius="md"
                            bg="white"
                            justify="space-between"
                        >
                            <HStack gap={3}>
                                <Image
                                    src={product.image_url || null}
                                    boxSize="40px"
                                    objectFit="cover"
                                    borderRadius="sm"
                                    fallbackSrc="https://via.placeholder.com/40"
                                />
                                <Box>
                                    <Text fontSize="sm" fontWeight="medium" lineClamp={1}>
                                        {product.name}
                                    </Text>
                                    <Text fontSize="xs" color="gray.500">
                                        SKU: {product.sku}
                                    </Text>
                                </Box>
                            </HStack>
                            <IconButton
                                aria-label="Удалить"
                                size="sm"
                                variant="ghost"
                                colorPalette="red"
                                onClick={() => handleRemove(product.id)}
                            >
                                <FaTimes />
                            </IconButton>
                        </HStack>
                    ))}
                </VStack>
            )}
        </Box>
    );
};
