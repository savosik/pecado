import React from 'react';
import { Box, Button, HStack, VStack } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';

/**
 * FilterPanel - контейнер для фильтров с кнопкой очистки
 * 
 * @param {ReactNode} children - Содержимое фильтров
 * @param {Function} onClear - Callback очистки фильтров
 * @param {string} clearLabel - Текст кнопки очистки
 * @param {boolean} showClear - Показывать ли кнопку очистки
 */
export const FilterPanel = ({
    children,
    onClear,
    clearLabel = 'Очистить фильтры',
    showClear = true,
}) => {
    return (
        <Box
            bg="bg.subtle"
            borderWidth="1px"
            borderColor="border.muted"
            borderRadius="md"
            p={4}
            mb={4}
        >
            <VStack align="stretch" gap={4}>
                <HStack gap={3} wrap="wrap">
                    {children}
                </HStack>

                {showClear && onClear && (
                    <Box>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={onClear}
                        >
                            <LuX />
                            {clearLabel}
                        </Button>
                    </Box>
                )}
            </VStack>
        </Box>
    );
};
