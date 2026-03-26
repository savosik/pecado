import React, { useState } from 'react';
import {
    Box,
    Input,
    Badge,
    HStack,
    Text,
} from '@chakra-ui/react';
import { FaTimes } from 'react-icons/fa';

/**
 * BarcodeSelector - компонент для управления списком штрихкодов
 * 
 * @param {string[]} value - Массив штрихкодов
 * @param {Function} onChange - Callback изменения
 * @param {string} placeholder - Placeholder
 * @param {string} error - Текст ошибки
 * @param {boolean} disabled - Отключить поле
 */
export const BarcodeSelector = ({
    value = [],
    onChange,
    placeholder = 'Введите штрихкод и нажмите Enter...',
    error = null,
    disabled = false,
}) => {
    const [inputValue, setInputValue] = useState('');

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addBarcode(inputValue);
        } else if (e.key === 'Backspace' && !inputValue && value.length > 0) {
            removeBarcode(value.length - 1);
        }
    };

    const addBarcode = (barcode) => {
        const trimmed = barcode.trim();
        if (trimmed && !value.includes(trimmed)) {
            onChange([...value, trimmed]);
        }
        setInputValue('');
    };

    const removeBarcode = (index) => {
        const newValue = [...value];
        newValue.splice(index, 1);
        onChange(newValue);
    };

    return (
        <Box position="relative" width="100%">
            <Box
                borderWidth="1px"
                borderColor={error ? "red.500" : "gray.200"}
                borderRadius="md"
                p={2}
                bg={disabled ? "gray.50" : "white"}
                _focusWithin={{ borderColor: "blue.500", boxShadow: "0 0 0 1px var(--chakra-colors-blue-500)" }}
            >
                <HStack wrap="wrap" gap={2}>
                    {value.map((barcode, index) => (
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
                            {barcode}
                            {!disabled && (
                                <Box
                                    as="span"
                                    cursor="pointer"
                                    onClick={() => removeBarcode(index)}
                                    _hover={{ opacity: 0.8 }}
                                >
                                    <FaTimes size="10px" />
                                </Box>
                            )}
                        </Badge>
                    ))}
                    <Input
                        variant="unstyled"
                        placeholder={value.length === 0 ? placeholder : ''}
                        value={inputValue}
                        onChange={(e) => setInputValue(e.target.value)}
                        onKeyDown={handleKeyDown}
                        disabled={disabled}
                        minWidth="150px"
                        flex="1"
                        p={1}
                        outline="none"
                    />
                </HStack>
            </Box>

            {/* Ошибка */}
            {error && (
                <Text color="red.500" fontSize="sm" mt={1}>
                    {error}
                </Text>
            )}
        </Box>
    );
};
