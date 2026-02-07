import React, { useState, useEffect, useRef } from 'react';
import {
    Box,
    Input,
    VStack,
    Text,
    Spinner,
} from '@chakra-ui/react';
import axios from 'axios';

/**
 * EntitySelector - универсальный async-селектор с поиском
 * 
 * @param {Object} value - Выбранное значение { id, name/label, ... }
 * @param {Function} onChange - Callback при изменении значения
 * @param {string} searchUrl - URL для поиска (route name)
 * @param {Object} searchParams - Дополнительные параметры для поиска
 * @param {string} placeholder - Placeholder для инпута
 * @param {string} displayField - Поле для отображения (default: 'label')
 * @param {boolean} disabled - Отключен ли селектор
 * @param {string} error - Ошибка валидации
 */
export const EntitySelector = ({
    value = null,
    onChange,
    searchUrl,
    searchParams = {},
    placeholder = 'Начните вводить для поиска...',
    displayField = 'label',
    disabled = false,
    error = null,
}) => {
    const [query, setQuery] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const [isFocused, setIsFocused] = useState(false);
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

    // Search with debounce
    useEffect(() => {
        const timer = setTimeout(() => {
            if (query.trim().length >= 1 && isFocused) {
                search(query);
            } else if (query.trim().length === 0 && isFocused) {
                // Load initial options when focused with empty query
                search('');
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [query, isFocused, JSON.stringify(searchParams)]);

    const search = async (searchQuery) => {
        setLoading(true);
        try {
            const url = searchUrl.startsWith('http') ? searchUrl : route(searchUrl);
            const response = await axios.get(url, {
                params: { query: searchQuery, ...searchParams }
            });
            setSuggestions(response.data);
            setShowSuggestions(true);
        } catch (error) {
            console.error('Search error:', error);
            setSuggestions([]);
        } finally {
            setLoading(false);
        }
    };

    const handleSelect = (item) => {
        onChange(item);
        setQuery('');
        setShowSuggestions(false);
    };

    const handleClear = () => {
        onChange(null);
        setQuery('');
    };

    const handleFocus = () => {
        setIsFocused(true);
        // useEffect will trigger search when isFocused becomes true
    };

    const handleBlur = () => {
        // Delay to allow click on suggestion
        setTimeout(() => {
            setIsFocused(false);
        }, 200);
    };

    return (
        <Box position="relative" ref={containerRef} w="100%">
            {value ? (
                <Box
                    px={3}
                    py={2}
                    h="40px"
                    borderWidth="1px"
                    borderRadius="md"
                    bg="bg.subtle"
                    display="flex"
                    justifyContent="space-between"
                    alignItems="center"
                >
                    <Text truncate>{value[displayField] || value.name || value.label}</Text>
                    {!disabled && (
                        <Text
                            as="button"
                            type="button"
                            color="red.500"
                            cursor="pointer"
                            onClick={handleClear}
                            fontSize="sm"
                            ml={2}
                            flexShrink={0}
                        >
                            ✕
                        </Text>
                    )}
                </Box>
            ) : (
                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    onFocus={handleFocus}
                    onBlur={handleBlur}
                    placeholder={placeholder}
                    disabled={disabled}
                    borderColor={error ? 'red.500' : undefined}
                />
            )}

            {showSuggestions && !value && (
                <Box
                    position="absolute"
                    top="100%"
                    left={0}
                    right={0}
                    zIndex={1000}
                    bg="bg.panel"
                    borderWidth="1px"
                    borderRadius="md"
                    boxShadow="lg"
                    maxH="300px"
                    overflowY="auto"
                    mt={1}
                >
                    {loading ? (
                        <Box p={4} textAlign="center">
                            <Spinner size="sm" />
                        </Box>
                    ) : suggestions.length > 0 ? (
                        <VStack align="stretch" gap={0}>
                            {suggestions.map((item) => (
                                <Box
                                    key={item.id}
                                    p={3}
                                    cursor="pointer"
                                    _hover={{ bg: 'bg.subtle' }}
                                    onClick={() => handleSelect(item)}
                                    borderBottomWidth="1px"
                                    _last={{ borderBottomWidth: 0 }}
                                >
                                    <Text fontWeight="medium">{item[displayField] || item.name || item.label}</Text>
                                    {item.email && (
                                        <Text fontSize="xs" color="fg.muted">{item.email}</Text>
                                    )}
                                </Box>
                            ))}
                        </VStack>
                    ) : (
                        <Box p={4} textAlign="center" color="fg.muted">
                            Ничего не найдено
                        </Box>
                    )}
                </Box>
            )}
        </Box>
    );
};

export default EntitySelector;
