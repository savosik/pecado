import { useState, useMemo } from 'react';
import { Box, Flex, Input, Text } from '@chakra-ui/react';
import { LuCheck, LuSearch } from 'react-icons/lu';

const SEARCH_THRESHOLD = 10;

/**
 * BrandFilter — фильтр по брендам с чекбоксами, поиском и счётчиками.
 *
 * Показывает поле поиска, если брендов >= 10.
 * Бренды с count=0 скрываются, кроме уже выбранных.
 *
 * @param {{
 *   brands: Array<{ id: number, name: string, slug: string, count: number }>,
 *   selectedIds: number[],
 *   onChange: (ids: number[]) => void,
 * }} props
 */
export default function BrandFilter({ brands = [], selectedIds = [], onChange }) {
    const [search, setSearch] = useState('');
    const selectedSet = new Set(selectedIds.map(Number));

    const visible = useMemo(() => {
        let filtered = brands.filter(
            (brand) => brand.count > 0 || selectedSet.has(brand.id)
        );

        if (search.trim()) {
            const q = search.trim().toLowerCase();
            filtered = filtered.filter((brand) =>
                brand.name.toLowerCase().includes(q)
            );
        }

        return filtered;
    }, [brands, search, selectedSet.size]); // eslint-disable-line react-hooks/exhaustive-deps

    const showSearch = brands.length >= SEARCH_THRESHOLD;

    const handleToggle = (id) => {
        const numId = Number(id);
        if (selectedSet.has(numId)) {
            onChange(selectedIds.filter((v) => Number(v) !== numId));
        } else {
            onChange([...selectedIds, numId]);
        }
    };

    return (
        <Flex direction="column" gap="2">
            {/* Поиск */}
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
                        placeholder="Найти бренд..."
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

            {/* Список */}
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
                        {search ? 'Ничего не найдено' : 'Нет доступных брендов'}
                    </Text>
                ) : (
                    visible.map((brand) => {
                        const isChecked = selectedSet.has(brand.id);

                        return (
                            <Flex
                                key={brand.id}
                                as="button"
                                type="button"
                                align="center"
                                gap="2"
                                px="1"
                                py="1"
                                borderRadius="md"
                                cursor="pointer"
                                transition="all 0.15s"
                                bg={isChecked ? 'pecado.50' : 'transparent'}
                                _dark={{ bg: isChecked ? 'pecado.900/30' : 'transparent' }}
                                _hover={{
                                    bg: isChecked ? 'pecado.100' : 'gray.50',
                                    _dark: { bg: isChecked ? 'pecado.900/40' : 'gray.700' },
                                }}
                                onClick={() => handleToggle(brand.id)}
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
                                        lineHeight="1.3"
                                    >
                                        {brand.name}
                                    </Text>
                                    <Text
                                        fontSize="xs"
                                        color="gray.400"
                                        _dark={{ color: 'gray.500' }}
                                        flexShrink="0"
                                    >
                                        {brand.count}
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
