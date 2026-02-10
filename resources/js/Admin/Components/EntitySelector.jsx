import React, { useState, useEffect, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';
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
 * @param {number|string|Object|null} value - Выбранное значение (ID или объект { id, name/label, ... })
 * @param {Function} onChange - Callback при изменении значения. Получает объект item или null (при очистке)
 * @param {string} searchUrl - URL для поиска (полный URL или route name)
 * @param {Object} searchParams - Дополнительные параметры для поиска
 * @param {string} placeholder - Placeholder для инпута
 * @param {string} displayField - Поле для отображения (default: 'label')
 * @param {boolean} disabled - Отключен ли селектор
 * @param {string} error - Ошибка валидации
 * @param {string} initialDisplay - Текст для отображения когда value — скалярный ID (для Edit-страниц)
 * @param {string} valueKey - Ключ, по которому из item извлекается значение для onChange (default: null — передаёт весь объект)
 * @param {Function} renderItem - Кастомная функция рендеринга элемента списка (item) => ReactNode
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
    initialDisplay = null,
    valueKey = null,
    renderItem = null,
}) => {
    const [query, setQuery] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const [isFocused, setIsFocused] = useState(false);
    // Внутренний стейт для хранения отображаемого текста при скалярном value
    const [selectedDisplayText, setSelectedDisplayText] = useState(initialDisplay || '');
    const containerRef = useRef(null);
    const [dropdownStyle, setDropdownStyle] = useState({});

    // Определяем, является ли значение "выбранным" (не пустым)
    const isValueObject = value !== null && typeof value === 'object';
    const isValueScalar = value !== null && value !== '' && typeof value !== 'object';
    const hasValue = isValueObject || isValueScalar;

    // Получаем текст для отображения выбранного значения
    const getDisplayText = () => {
        if (isValueObject) {
            return value[displayField] || value.name || value.label || '';
        }
        if (isValueScalar && selectedDisplayText) {
            return selectedDisplayText;
        }
        return '';
    };

    // Обновляем selectedDisplayText при изменении initialDisplay
    useEffect(() => {
        if (initialDisplay) {
            setSelectedDisplayText(initialDisplay);
        }
    }, [initialDisplay]);

    // Обновить позицию dropdown
    const updateDropdownPosition = useCallback(() => {
        if (containerRef.current) {
            const rect = containerRef.current.getBoundingClientRect();
            setDropdownStyle({
                position: 'fixed',
                top: rect.bottom + 4,
                left: rect.left,
                width: rect.width,
                zIndex: 10000,
            });
        }
    }, []);

    // Click outside handler
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                // Также проверяем, не кликнули ли на dropdown в портале
                const portalEl = document.getElementById('entity-selector-portal');
                if (portalEl && portalEl.contains(event.target)) return;
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
        // Сохраняем текст для отображения
        setSelectedDisplayText(item[displayField] || item.name || item.label || '');
        // Если задан valueKey, передаём только нужное поле (например, id)
        if (valueKey) {
            onChange(item[valueKey]);
        } else {
            onChange(item);
        }
        setQuery('');
        setShowSuggestions(false);
    };

    const handleClear = () => {
        setSelectedDisplayText('');
        onChange(null);
        setQuery('');
    };

    const handleFocus = () => {
        setIsFocused(true);
        updateDropdownPosition();
    };

    const handleBlur = () => {
        // Delay to allow click on suggestion
        setTimeout(() => {
            setIsFocused(false);
        }, 200);
    };

    return (
        <Box position="relative" ref={containerRef} w="100%">
            {hasValue ? (
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
                    <Text truncate>{getDisplayText()}</Text>
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

            {showSuggestions && !hasValue && createPortal(
                <Box
                    id="entity-selector-portal"
                    style={dropdownStyle}
                    bg="bg.panel"
                    borderWidth="1px"
                    borderRadius="md"
                    boxShadow="lg"
                    maxH="300px"
                    overflowY="auto"
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
                                    {renderItem ? renderItem(item) : (
                                        <>
                                            <Text fontWeight="medium">{item[displayField] || item.name || item.label}</Text>
                                            {item.email && (
                                                <Text fontSize="xs" color="fg.muted">{item.email}</Text>
                                            )}
                                        </>
                                    )}
                                </Box>
                            ))}
                        </VStack>
                    ) : (
                        <Box p={4} textAlign="center" color="fg.muted">
                            Ничего не найдено
                        </Box>
                    )}
                </Box>,
                document.body
            )}
        </Box>
    );
};

export default EntitySelector;
