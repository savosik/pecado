import { useState, useMemo } from 'react';
import { Box, Flex, Input, Text } from '@chakra-ui/react';
import { LuCheck, LuSearch } from 'react-icons/lu';
import FilterBlock from './FilterBlock';

const SEARCH_THRESHOLD = 10;

/**
 * AttributeFilters — динамические блоки фильтров по характеристикам.
 *
 * Каждый атрибут — отдельный FilterBlock с чекбоксами по значениям.
 * Поиск внутри блока при ≥10 значений.
 * Значения с count=0 скрываются, кроме уже выбранных.
 *
 * Поддерживает два типа атрибутов:
 * - select: значения с integer id → onChange (attribute_value_ids)
 * - inline: значения с string id (inline:attr_id:raw_value) → onInlineChange (attribute_inline_filters)
 *
 * @param {{
 *   attributes: Array<{ id: number, name: string, type?: string, values: Array<{ id: number|string, value: string, raw_value?: string, count: number }> }>,
 *   selectedValueIds: number[],
 *   selectedInlineFilters: object,
 *   onChange: (valueIds: number[]) => void,
 *   onInlineChange: (filters: object) => void,
 * }} props
 */
export default function AttributeFilters({ attributes = [], selectedValueIds = [], selectedInlineFilters = {}, onChange, onInlineChange }) {
    if (!attributes || attributes.length === 0) return null;

    const selectedSet = new Set(selectedValueIds.map(Number));

    // Собираем выбранные inline значения в Set для быстрого доступа
    const selectedInlineSet = new Set();
    if (selectedInlineFilters && typeof selectedInlineFilters === 'object') {
        for (const [attrId, values] of Object.entries(selectedInlineFilters)) {
            if (Array.isArray(values)) {
                values.forEach((v) => selectedInlineSet.add(`inline:${attrId}:${v}`));
            }
        }
    }

    const handleToggle = (attr, valueId) => {
        const isInline = attr.type === 'inline';

        if (isInline) {
            // Значение типа "inline:3:15" → извлекаем attrId и rawValue
            const parts = String(valueId).match(/^inline:(\d+):(.+)$/);
            if (!parts) return;
            const attrId = parts[1];
            const rawValue = parts[2];

            const current = { ...selectedInlineFilters };
            const currentValues = [...(current[attrId] || [])];

            if (currentValues.includes(rawValue)) {
                const newValues = currentValues.filter((v) => v !== rawValue);
                if (newValues.length > 0) {
                    current[attrId] = newValues;
                } else {
                    delete current[attrId];
                }
            } else {
                current[attrId] = [...currentValues, rawValue];
            }

            onInlineChange(Object.keys(current).length > 0 ? current : undefined);
        } else {
            const numId = Number(valueId);
            if (selectedSet.has(numId)) {
                onChange(selectedValueIds.filter((v) => Number(v) !== numId));
            } else {
                onChange([...selectedValueIds, numId]);
            }
        }
    };

    // Проверяем, есть ли выбранные значения у атрибута — для кнопки «Очистить»
    const getSelectedForAttr = (attr) => {
        if (attr.type === 'inline') {
            return attr.values.filter((v) => selectedInlineSet.has(v.id));
        }
        return attr.values.filter((v) => selectedSet.has(v.id)).map((v) => v.id);
    };

    const handleClearAttr = (attr) => {
        if (attr.type === 'inline') {
            const current = { ...selectedInlineFilters };
            delete current[attr.id];
            onInlineChange(Object.keys(current).length > 0 ? current : undefined);
        } else {
            const attrValueIds = new Set(attr.values.map((v) => v.id));
            onChange(selectedValueIds.filter((id) => !attrValueIds.has(Number(id))));
        }
    };

    const isValueSelected = (attr, val) => {
        if (attr.type === 'inline') {
            return selectedInlineSet.has(val.id);
        }
        return selectedSet.has(val.id);
    };

    return (
        <>
            {attributes.map((attr) => {
                const selectedForAttr = getSelectedForAttr(attr);

                return (
                    <Box key={attr.id}>
                        <Box h="1px" bg="bg.muted" my="1" />
                        <FilterBlock
                            title={attr.name}
                            showClear={selectedForAttr.length > 0}
                            onClear={() => handleClearAttr(attr)}
                            defaultOpen={selectedForAttr.length > 0}
                        >
                            <AttributeValueList
                                attr={attr}
                                values={attr.values}
                                isValueSelected={(val) => isValueSelected(attr, val)}
                                onToggle={(valId) => handleToggle(attr, valId)}
                            />
                        </FilterBlock>
                    </Box>
                );
            })}
        </>
    );
}

/**
 * Список значений одного атрибута с поиском и чекбоксами.
 */
function AttributeValueList({ attr, values, isValueSelected, onToggle }) {
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        let filtered = values.filter(
            (v) => v.count > 0 || isValueSelected(v)
        );

        if (search.trim()) {
            const q = search.trim().toLowerCase();
            filtered = filtered.filter((v) =>
                String(v.value).toLowerCase().includes(q)
            );
        }

        return filtered;
    }, [values, search, isValueSelected]);

    const showSearch = values.length >= SEARCH_THRESHOLD;

    return (
        <Flex direction="column" gap="2">
            {showSearch && (
                <Box position="relative">
                    <Box
                        position="absolute"
                        left="3"
                        top="50%"
                        transform="translateY(-50%)"
                        color="gray.400"
                        pointerEvents="none"
                        zIndex="1"
                    >
                        <LuSearch size={14} />
                    </Box>
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Найти..."
                        size="xs"
                        pl="8"
                        borderRadius="md"
                        _focus={{
                            borderColor: 'pecado.400',
                            boxShadow: '0 0 0 1px var(--chakra-colors-pecado-400)',
                        }}
                    />
                </Box>
            )}

            <Flex
                direction="column"
                gap="0.5"
                maxH={showSearch ? '240px' : undefined}
                overflowY={showSearch ? 'auto' : undefined}
                css={showSearch ? {
                    '&::-webkit-scrollbar': { width: '4px' },
                    '&::-webkit-scrollbar-thumb': {
                        background: 'var(--chakra-colors-gray-300)',
                        borderRadius: '4px',
                    },
                } : undefined}
            >
                {visible.length === 0 ? (
                    <Text fontSize="xs" color="gray.400">
                        {search ? 'Ничего не найдено' : 'Нет доступных значений'}
                    </Text>
                ) : (
                    visible.map((val) => {
                        const isChecked = isValueSelected(val);

                        return (
                            <Flex
                                key={val.id}
                                as="button"
                                type="button"
                                align="center"
                                gap="2.5"
                                px="2"
                                py="1.5"
                                borderRadius="md"
                                cursor="pointer"
                                transition="all 0.15s"
                                bg={isChecked ? 'pecado.50' : 'transparent'}
                                _dark={{ bg: isChecked ? 'pecado.900/30' : 'transparent' }}
                                _hover={{
                                    bg: isChecked ? 'pecado.100' : 'gray.50',
                                    _dark: { bg: isChecked ? 'pecado.900/40' : 'gray.700' },
                                }}
                                onClick={() => onToggle(val.id)}
                            >
                                {/* Custom checkbox */}
                                <Box
                                    w="16px"
                                    h="16px"
                                    borderRadius="sm"
                                    border="2px solid"
                                    borderColor={isChecked ? 'pecado.500' : 'gray.300'}
                                    _dark={{ borderColor: isChecked ? 'pecado.400' : 'gray.600' }}
                                    bg={isChecked ? 'pecado.500' : 'transparent'}
                                    display="flex"
                                    alignItems="center"
                                    justifyContent="center"
                                    flexShrink="0"
                                    transition="all 0.15s"
                                >
                                    {isChecked && <LuCheck size={12} color="white" />}
                                </Box>

                                <Flex flex="1" justify="space-between" align="center" gap="1">
                                    <Text
                                        fontSize="sm"
                                        color={isChecked ? 'pecado.600' : 'gray.700'}
                                        _dark={{ color: isChecked ? 'pecado.300' : 'gray.300' }}
                                        fontWeight={isChecked ? '500' : '400'}
                                        textAlign="left"
                                    >
                                        {val.value}
                                    </Text>
                                    <Text
                                        fontSize="xs"
                                        color="gray.400"
                                        _dark={{ color: 'gray.500' }}
                                        flexShrink="0"
                                    >
                                        {val.count}
                                    </Text>
                                </Flex>
                            </Flex>
                        );
                    })
                )}
            </Flex>
        </Flex>
    );
}
