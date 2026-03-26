import React, { useState, useEffect, useRef } from 'react';
import {
    Box,
    Input,
    Badge,
    HStack,
    VStack,
    Text,
    Portal,
    Spinner,
    createListCollection,
} from '@chakra-ui/react';
import { FaTimes } from 'react-icons/fa';
import axios from 'axios';

/**
 * TagSelector - компонент для выбора и создания тегов
 * 
 * @param {string[]} value - Массив выбранных тегов (строки)
 * @param {Function} onChange - Callback изменения
 * @param {string} placeholder - Placeholder
 * @param {string} error - Текст ошибки
 * @param {boolean} disabled - Отключить поле
 */
export const TagSelector = ({
    value = [],
    onChange,
    placeholder = 'Введите тег и нажмите Enter...',
    error = null,
    disabled = false,
}) => {
    const [inputValue, setInputValue] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [showSuggestions, setShowSuggestions] = useState(false);

    // Используем ref для click outside
    const containerRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setShowSuggestions(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Проверка, является ли value массивом и нормализация тегов
    const tags = Array.isArray(value)
        ? value.map(t => {
            if (typeof t === 'object' && t !== null) {
                // Если тег пришел как объект (например, из БД), берем name
                // Spatie tags могут возвращать name как JSON с локалями {ru: "...", en: "..."}
                const name = t.name;
                if (typeof name === 'object' && name !== null) {
                    // Берём первый доступный перевод
                    return name.ru || name.en || Object.values(name)[0] || '';
                }
                return name || '';
            }
            return t;
        })
        : [];

    // Загрузка подсказок
    const loadSuggestions = async (query) => {
        if (!query || query.length < 2) {
            setSuggestions([]);
            return;
        }

        setLoading(true);
        try {
            const response = await axios.get(route('admin.tags.search'), {
                params: { query },
            });
            setSuggestions(response.data);
            setShowSuggestions(true);
        } catch (error) {
            console.error('Ошибка загрузки тегов:', error);
        } finally {
            setLoading(false);
        }
    };

    // Debounce для поиска
    useEffect(() => {
        const timer = setTimeout(() => {
            if (inputValue) {
                loadSuggestions(inputValue);
            } else {
                setSuggestions([]);
                setShowSuggestions(false);
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [inputValue]);

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addTag(inputValue);
        } else if (e.key === 'Backspace' && !inputValue && tags.length > 0) {
            removeTag(tags.length - 1);
        }
    };

    const addTag = (tagName) => {
        const trimmed = tagName.trim();
        if (trimmed && !tags.includes(trimmed)) {
            onChange([...tags, trimmed]);
        }
        setInputValue('');
        setSuggestions([]);
        setShowSuggestions(false);
    };

    const removeTag = (index) => {
        const newTags = [...tags];
        newTags.splice(index, 1);
        onChange(newTags);
    };

    const handleSuggestionClick = (tagName) => {
        addTag(tagName);
    };

    return (
        <Box ref={containerRef} position="relative" width="100%">
            <Box
                borderWidth="1px"
                borderColor={error ? "red.500" : "gray.200"}
                borderRadius="md"
                p={2}
                bg={disabled ? "gray.50" : "white"}
                _focusWithin={{ borderColor: "brand.500", boxShadow: "0 0 0 1px var(--chakra-colors-brand-500)" }}
            >
                <HStack wrap="wrap" gap={2}>
                    {tags.map((tag, index) => (
                        <Badge
                            key={index}
                            colorPalette="blue"
                            variant="subtle"
                            px={2}
                            py={1}
                            borderRadius="md"
                            display="flex"
                            alignItems="center"
                            gap={1}
                        >
                            {tag}
                            {!disabled && (
                                <Box
                                    as="span"
                                    cursor="pointer"
                                    onClick={() => removeTag(index)}
                                    _hover={{ opacity: 0.8 }}
                                >
                                    <FaTimes size="10px" />
                                </Box>
                            )}
                        </Badge>
                    ))}
                    <Input
                        variant="unstyled"
                        placeholder={tags.length === 0 ? placeholder : ''}
                        value={inputValue}
                        onChange={(e) => setInputValue(e.target.value)}
                        onKeyDown={handleKeyDown}
                        disabled={disabled}
                        minWidth="120px"
                        flex="1"
                        p={1}
                    />
                </HStack>
            </Box>

            {/* Ошибка */}
            {error && (
                <Text color="red.500" fontSize="sm" mt={1}>
                    {error}
                </Text>
            )}

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
                    maxHeight="200px"
                    overflowY="auto"
                    borderWidth="1px"
                    borderColor="gray.200"
                >
                    {loading ? (
                        <Box p={2} textAlign="center">
                            <Spinner size="sm" />
                        </Box>
                    ) : (
                        <VStack align="stretch" gap={0}>
                            {suggestions.map((item) => (
                                <Box
                                    key={item.id}
                                    p={2}
                                    cursor="pointer"
                                    _hover={{ bg: "gray.100" }}
                                    onClick={() => handleSuggestionClick(item.name)}
                                >
                                    <Text fontSize="sm">{item.name}</Text>
                                </Box>
                            ))}
                            {inputValue && !suggestions.some(s => s.name.toLowerCase() === inputValue.trim().toLowerCase()) && (
                                <Box
                                    p={2}
                                    cursor="pointer"
                                    _hover={{ bg: "blue.50" }}
                                    onClick={() => addTag(inputValue)}
                                    borderTopWidth="1px"
                                    borderColor="gray.100"
                                >
                                    <Text fontSize="sm" color="blue.600">
                                        Создать "{inputValue}"
                                    </Text>
                                </Box>
                            )}
                        </VStack>
                    )}
                </Box>
            )}
        </Box>
    );
};
