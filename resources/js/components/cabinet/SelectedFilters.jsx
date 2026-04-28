import { Box, Flex, Text } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';

/**
 * SelectedFilters — универсальные «чипы» активных фильтров для разделов кабинета.
 *
 * Универсальность достигается через `fields` — массив описаний полей фильтра.
 * Поддерживается скаляр и массив значений (multi-select даёт по чипу на элемент).
 *
 * @param {{
 *   filters: Record<string, any>,
 *   fields: Array<{
 *     key: string,
 *     label?: string,
 *     formatter?: (value: any, filters: object) => string | null,
 *     valueKey?: (value: any) => string | number,
 *   }>,
 *   onRemove: (key: string, value?: any) => void,
 *   onResetAll?: () => void,
 *   resetLabel?: string,
 * }} props
 */
export default function SelectedFilters({
    filters = {},
    fields = [],
    onRemove,
    onResetAll,
    resetLabel = 'Сбросить всё',
}) {
    const chips = buildChips(filters, fields);

    if (chips.length === 0) return null;

    return (
        <Flex
            gap="2"
            flexWrap="wrap"
            align="center"
            mb="4"
        >
            {chips.map((chip) => (
                <Flex
                    key={chip.key}
                    align="center"
                    gap="1"
                    bg="pecado.50"
                    _dark={{ bg: 'pecado.900/30', color: 'pecado.200' }}
                    color="pecado.700"
                    borderRadius="full"
                    px="3"
                    py="1"
                    fontSize="xs"
                    fontWeight="500"
                    transition="all 0.15s"
                    _hover={{ bg: 'pecado.100', _dark: { bg: 'pecado.900/50' } }}
                >
                    <Text>{chip.label}</Text>
                    <Box
                        as="button"
                        type="button"
                        display="flex"
                        alignItems="center"
                        cursor="pointer"
                        color="pecado.400"
                        _hover={{ color: 'pecado.600' }}
                        ml="0.5"
                        aria-label={`Убрать фильтр: ${chip.label}`}
                        onClick={() => onRemove?.(chip.filterKey, chip.value)}
                    >
                        <LuX size={12} />
                    </Box>
                </Flex>
            ))}

            {onResetAll && (
                <Text
                    as="button"
                    type="button"
                    fontSize="xs"
                    color="gray.500"
                    _hover={{ color: 'pecado.500' }}
                    cursor="pointer"
                    ml="1"
                    onClick={onResetAll}
                >
                    {resetLabel}
                </Text>
            )}
        </Flex>
    );
}

function isEmptyValue(value) {
    if (value === null || value === undefined) return true;
    if (typeof value === 'string' && value.trim() === '') return true;
    if (Array.isArray(value) && value.length === 0) return true;
    return false;
}

function buildChips(filters, fields) {
    const chips = [];

    for (const field of fields) {
        const raw = filters?.[field.key];
        if (isEmptyValue(raw)) continue;

        const formatValue = (value) => {
            const formatted = field.formatter ? field.formatter(value, filters) : String(value);
            if (formatted === null || formatted === undefined || formatted === '') return null;
            return field.label ? `${field.label}: ${formatted}` : formatted;
        };

        if (Array.isArray(raw)) {
            raw.forEach((value) => {
                const label = formatValue(value);
                if (!label) return;
                const tag = field.valueKey ? field.valueKey(value) : value;
                chips.push({
                    key: `${field.key}_${tag}`,
                    filterKey: field.key,
                    value,
                    label,
                });
            });
        } else {
            const label = formatValue(raw);
            if (!label) continue;
            chips.push({
                key: field.key,
                filterKey: field.key,
                value: raw,
                label,
            });
        }
    }

    return chips;
}
