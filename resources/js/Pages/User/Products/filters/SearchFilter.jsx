import { useState, useEffect, useRef } from 'react';
import { Box, Flex, Input, IconButton } from '@chakra-ui/react';
import { LuSearch, LuX } from 'react-icons/lu';

const DEBOUNCE_MS = 300;

/**
 * SearchFilter — поле поиска с debounce 300ms.
 *
 * @param {{
 *   value?: string,
 *   onChange: (q: string) => void,
 * }} props
 */
export default function SearchFilter({ value = '', onChange }) {
    const [localValue, setLocalValue] = useState(value);
    const timerRef = useRef(null);
    const mountedRef = useRef(false);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    // Синхронизация с внешним значением (например, при сбросе фильтров)
    useEffect(() => {
        setLocalValue(value || '');
    }, [value]);

    // Debounced onChange
    useEffect(() => {
        // Не вызываем onChange при mount
        if (!mountedRef.current) {
            mountedRef.current = true;
            return;
        }

        timerRef.current = setTimeout(() => {
            onChangeRef.current(localValue.trim());
        }, DEBOUNCE_MS);

        return () => clearTimeout(timerRef.current);
    }, [localValue]);

    const handleClear = () => {
        setLocalValue('');
        onChangeRef.current('');
    };

    return (
        <Box position="relative">
            <Flex align="center" position="relative">
                <Box
                    position="absolute"
                    left="3"
                    color="gray.400"
                    pointerEvents="none"
                    zIndex="1"
                >
                    <LuSearch size={16} />
                </Box>

                <Input
                    value={localValue}
                    onChange={(e) => setLocalValue(e.target.value)}
                    placeholder="Поиск товаров..."
                    size="sm"
                    pl="9"
                    pr={localValue ? '9' : '3'}
                    borderRadius="lg"
                    _focus={{
                        borderColor: 'pecado.400',
                        boxShadow: '0 0 0 1px var(--chakra-colors-pecado-400)',
                    }}
                />

                {localValue && (
                    <IconButton
                        aria-label="Очистить поиск"
                        size="xs"
                        variant="ghost"
                        position="absolute"
                        right="1"
                        color="gray.400"
                        _hover={{ color: 'gray.600' }}
                        onClick={handleClear}
                    >
                        <LuX size={14} />
                    </IconButton>
                )}
            </Flex>
        </Box>
    );
}
