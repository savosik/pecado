import React, { useState, useEffect, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';
import {
    Box,
    Input,
    Badge,
    HStack,
    VStack,
    Text,
    Spinner,
} from '@chakra-ui/react';
import { FaTimes } from 'react-icons/fa';
import axios from 'axios';

/**
 * MultiEntitySelector — универсальный async-мультиселектор с поиском.
 *
 * Построен по образцу EntitySelector, но хранит массив выбранных элементов
 * и рендерит их как удаляемые чипы. Используется для фильтров по брендам,
 * категориям, тегам (меняется только searchUrl).
 *
 * @param {Array<{id:number,name:string}>} value - Выбранные элементы
 * @param {Function} onChange - Callback, получает новый массив выбранных
 * @param {string} searchUrl - Имя маршрута для поиска (Ziggy route name)
 * @param {string} placeholder - Placeholder инпута
 * @param {string} displayField - Поле для отображения (default: 'name')
 * @param {boolean} disabled - Отключить селектор
 */
export const MultiEntitySelector = ({
    value = [],
    onChange,
    searchUrl,
    placeholder = 'Начните вводить для поиска...',
    displayField = 'name',
    disabled = false,
}) => {
    const [query, setQuery] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const [isFocused, setIsFocused] = useState(false);
    const containerRef = useRef(null);
    const [dropdownStyle, setDropdownStyle] = useState({});

    const selected = Array.isArray(value) ? value : [];

    const getLabel = (item) => item[displayField] || item.name || item.label || '';

    // Позиционирование выпадающего списка (портал)
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

    // Закрытие при клике вне
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                const portalEl = document.getElementById('multi-entity-selector-portal');
                if (portalEl && portalEl.contains(event.target)) return;
                setShowSuggestions(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Поиск с debounce
    useEffect(() => {
        const timer = setTimeout(() => {
            if (isFocused && query.trim().length >= 1) {
                search(query);
            } else if (isFocused && query.trim().length === 0) {
                search('');
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [query, isFocused]);

    const search = async (searchQuery) => {
        setLoading(true);
        try {
            const url = searchUrl.startsWith('http') ? searchUrl : route(searchUrl);
            const response = await axios.get(url, { params: { query: searchQuery } });
            setSuggestions(Array.isArray(response.data) ? response.data : []);
            setShowSuggestions(true);
        } catch (error) {
            console.error('Ошибка поиска:', error);
            setSuggestions([]);
        } finally {
            setLoading(false);
        }
    };

    const addItem = (item) => {
        if (!selected.some((s) => s.id === item.id)) {
            onChange([...selected, { id: item.id, name: getLabel(item) }]);
        }
        setQuery('');
    };

    const removeItem = (id) => {
        onChange(selected.filter((s) => s.id !== id));
    };

    const handleFocus = () => {
        setIsFocused(true);
        updateDropdownPosition();
    };

    const handleBlur = () => {
        setTimeout(() => setIsFocused(false), 200);
    };

    return (
        <Box position="relative" ref={containerRef} w="100%">
            <Box
                borderWidth="1px"
                borderColor="border"
                borderRadius="md"
                px={2}
                py={1}
                bg={disabled ? 'bg.muted' : 'bg'}
                minH="40px"
                _focusWithin={{ borderColor: 'brand.500' }}
            >
                <HStack wrap="wrap" gap={2}>
                    {selected.map((item) => (
                        <Badge
                            key={item.id}
                            colorPalette="blue"
                            variant="subtle"
                            px={2}
                            py={1}
                            borderRadius="md"
                            display="flex"
                            alignItems="center"
                            gap={1}
                        >
                            {item.name}
                            {!disabled && (
                                <Box
                                    as="span"
                                    cursor="pointer"
                                    onClick={() => removeItem(item.id)}
                                    _hover={{ opacity: 0.7 }}
                                >
                                    <FaTimes size="10px" />
                                </Box>
                            )}
                        </Badge>
                    ))}
                    <Input
                        variant="unstyled"
                        placeholder={selected.length === 0 ? placeholder : ''}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onFocus={handleFocus}
                        onBlur={handleBlur}
                        disabled={disabled}
                        minWidth="120px"
                        flex="1"
                        p={1}
                    />
                </HStack>
            </Box>

            {showSuggestions && createPortal(
                <Box
                    id="multi-entity-selector-portal"
                    style={dropdownStyle}
                    // См. EntitySelector: модалка Chakra выключает pointer-events на body,
                    // портальной выпадашке их нужно вернуть явно.
                    pointerEvents="auto"
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
                            {suggestions
                                .filter((item) => !selected.some((s) => s.id === item.id))
                                .map((item) => (
                                    <Box
                                        key={item.id}
                                        p={3}
                                        cursor="pointer"
                                        _hover={{ bg: 'bg.subtle' }}
                                        onClick={() => addItem(item)}
                                        borderBottomWidth="1px"
                                        _last={{ borderBottomWidth: 0 }}
                                    >
                                        <Text fontWeight="medium">{getLabel(item)}</Text>
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

export default MultiEntitySelector;
