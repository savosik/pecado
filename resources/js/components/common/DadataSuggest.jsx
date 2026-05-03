import { useEffect, useRef, useState } from 'react';
import { Box, Input, Spinner } from '@chakra-ui/react';
import axios from 'axios';

/**
 * Универсальный autocomplete-компонент для подсказок DaData.
 *
 * Props:
 * - value: string — текущее значение поля
 * - onChange: (val: string) => void — пользователь напечатал текст
 * - onSelect: (suggestion: object) => void — пользователь выбрал вариант
 * - endpoint: string — серверный прокси-эндпоинт (POST)
 * - paramsBuilder: (query: string) => object — формирователь body для запроса
 * - getDisplayValue: (suggestion) => string — что показывать в списке
 * - getKey: (suggestion, index) => string|number — ключ React-элемента
 * - renderItem?: (suggestion) => ReactNode — кастомный рендер строки списка
 * - extractList: (responseData) => array — где искать массив подсказок в ответе
 * - placeholder, minChars (default 2), debounceMs (default 300)
 * - disabled, invalid — пропсы Chakra Input
 */
export function DadataSuggest({
    value,
    onChange,
    onSelect,
    endpoint,
    paramsBuilder,
    getDisplayValue,
    getKey,
    renderItem,
    extractList = (data) => data?.suggestions ?? [],
    placeholder,
    minChars = 2,
    debounceMs = 300,
    disabled = false,
    invalid = false,
    ...rest
}) {
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [activeIndex, setActiveIndex] = useState(-1);

    const debounceRef = useRef(null);
    const requestIdRef = useRef(0);
    const containerRef = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const onClickOutside = (e) => {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onClickOutside);
        return () => document.removeEventListener('mousedown', onClickOutside);
    }, [open]);

    const fetchSuggestions = (query) => {
        const currentRequestId = ++requestIdRef.current;
        setLoading(true);
        setError(null);
        axios
            .post(endpoint, paramsBuilder(query))
            .then((res) => {
                if (currentRequestId !== requestIdRef.current) return;
                setItems(extractList(res.data));
                setOpen(true);
                setActiveIndex(-1);
            })
            .catch(() => {
                if (currentRequestId !== requestIdRef.current) return;
                setError('Не удалось загрузить подсказки');
                setItems([]);
                setOpen(true);
            })
            .finally(() => {
                if (currentRequestId === requestIdRef.current) setLoading(false);
            });
    };

    const handleInputChange = (newValue) => {
        onChange(newValue);

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        if (!newValue || newValue.trim().length < minChars) {
            setItems([]);
            setOpen(false);
            return;
        }

        debounceRef.current = setTimeout(() => fetchSuggestions(newValue.trim()), debounceMs);
    };

    const handleSelect = (item) => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        setOpen(false);
        setItems([]);
        setActiveIndex(-1);
        onSelect?.(item);
    };

    const handleKeyDown = (e) => {
        if (!open || items.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex((i) => (i + 1) % items.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex((i) => (i <= 0 ? items.length - 1 : i - 1));
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && activeIndex < items.length) {
                e.preventDefault();
                handleSelect(items[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    };

    return (
        <Box position="relative" ref={containerRef} w="full">
            <Input
                value={value}
                onChange={(e) => handleInputChange(e.target.value)}
                onFocus={() => {
                    if (items.length > 0) setOpen(true);
                }}
                onKeyDown={handleKeyDown}
                placeholder={placeholder}
                disabled={disabled}
                aria-invalid={invalid || undefined}
                aria-autocomplete="list"
                aria-expanded={open}
                autoComplete="off"
                {...rest}
            />
            {loading && (
                <Box position="absolute" right="3" top="50%" transform="translateY(-50%)" pointerEvents="none">
                    <Spinner size="xs" />
                </Box>
            )}
            {open && (
                <Box
                    position="absolute"
                    top="100%"
                    left="0"
                    right="0"
                    mt="1"
                    bg="bg"
                    border="1px solid"
                    borderColor="border.muted"
                    borderRadius="md"
                    boxShadow="md"
                    zIndex="popover"
                    maxH="320px"
                    overflowY="auto"
                >
                    {error ? (
                        <Box px="3" py="2" fontSize="sm" color="fg.error">{error}</Box>
                    ) : items.length === 0 ? (
                        <Box px="3" py="2" fontSize="sm" color="fg.muted">Ничего не найдено</Box>
                    ) : (
                        items.map((item, idx) => {
                            const isActive = idx === activeIndex;
                            return (
                                <Box
                                    key={getKey ? getKey(item, idx) : idx}
                                    px="3"
                                    py="2"
                                    cursor="pointer"
                                    bg={isActive ? 'bg.subtle' : 'transparent'}
                                    _hover={{ bg: 'bg.subtle' }}
                                    onMouseDown={(e) => {
                                        e.preventDefault();
                                        handleSelect(item);
                                    }}
                                    onMouseEnter={() => setActiveIndex(idx)}
                                    fontSize="sm"
                                >
                                    {renderItem ? renderItem(item) : getDisplayValue(item)}
                                </Box>
                            );
                        })
                    )}
                </Box>
            )}
        </Box>
    );
}
