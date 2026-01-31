import React, { useState, useEffect } from 'react';
import { Input, InputGroup, IconButton } from '@chakra-ui/react';
import { LuSearch, LuX } from 'react-icons/lu';

/**
 * SearchInput - поле поиска с debounce и кнопкой очистки
 * 
 * @param {string} value - Текущее значение поиска
 * @param {Function} onChange - Callback изменения значения
 * @param {string} placeholder - Placeholder для поля
 * @param {number} debounceMs - Задержка debounce в миллисекундах
 */
export const SearchInput = ({
    value = '',
    onChange,
    placeholder = 'Поиск...',
    debounceMs = 300,
}) => {
    const [localValue, setLocalValue] = useState(value);

    // Синхронизация с внешним значением
    useEffect(() => {
        setLocalValue(value);
    }, [value]);

    // Debounce для onChange
    useEffect(() => {
        const timer = setTimeout(() => {
            if (localValue !== value) {
                onChange(localValue);
            }
        }, debounceMs);

        return () => clearTimeout(timer);
    }, [localValue, debounceMs]);

    const handleClear = () => {
        setLocalValue('');
        onChange('');
    };

    return (
        <InputGroup
            flex="1"
            startElement={<LuSearch />}
            endElement={
                localValue ? (
                    <IconButton
                        variant="ghost"
                        size="xs"
                        onClick={handleClear}
                        aria-label="Очистить"
                    >
                        <LuX />
                    </IconButton>
                ) : null
            }
        >
            <Input
                value={localValue}
                onChange={(e) => setLocalValue(e.target.value)}
                placeholder={placeholder}
            />
        </InputGroup>
    );
};
