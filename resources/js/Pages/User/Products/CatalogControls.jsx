import { Box, Flex, HStack, IconButton, NativeSelect, Text } from '@chakra-ui/react';
import { LuGrid2X2, LuLayoutList } from 'react-icons/lu';

const PER_PAGE_OPTIONS = [10, 20, 40, 60, 100];

/**
 * CatalogControls — панель управления видом, сортировкой и количеством на страницу.
 *
 * @param {{
 *   sort: string,
 *   view: string,
 *   perPage: number,
 *   sortOptions: Array<{value: string, label: string}>,
 *   onSortChange: (value: string) => void,
 *   onViewChange: (value: string) => void,
 *   onPerPageChange: (value: number) => void,
 * }} props
 */
export default function CatalogControls({
    sort,
    view,
    perPage,
    sortOptions = [],
    onSortChange,
    onViewChange,
    onPerPageChange,
}) {
    return (
        <Flex
            mb="4"
            gap="3"
            align="center"
            justify="space-between"
            flexWrap="wrap"
        >
            {/* Левая часть: сортировка + показывать по */}
            <Box
                overflowX="auto"
                css={{ '&::-webkit-scrollbar': { display: 'none' }, scrollbarWidth: 'none' }}
            >
                <HStack gap="3" flexShrink="0" minW="max-content">
                    {/* Сортировка */}
                    <HStack gap="1.5">
                        <Text fontSize="sm" color="gray.500" whiteSpace="nowrap">
                            Сортировка:
                        </Text>
                        <NativeSelect.Root size="sm" w="auto">
                            <NativeSelect.Field
                                value={sort}
                                onChange={(e) => onSortChange(e.target.value)}
                                borderRadius="md"
                                fontSize="sm"
                            >
                                {sortOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </NativeSelect.Field>
                            <NativeSelect.Indicator />
                        </NativeSelect.Root>
                    </HStack>

                    {/* Показывать по */}
                    <HStack gap="1.5">
                        <Text fontSize="sm" color="gray.500" whiteSpace="nowrap">
                            Показать:
                        </Text>
                        <NativeSelect.Root size="sm" w="auto">
                            <NativeSelect.Field
                                value={perPage}
                                onChange={(e) => onPerPageChange(Number(e.target.value))}
                                borderRadius="md"
                                fontSize="sm"
                            >
                                {PER_PAGE_OPTIONS.map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </NativeSelect.Field>
                            <NativeSelect.Indicator />
                        </NativeSelect.Root>
                    </HStack>
                </HStack>
            </Box>

            {/* Переключатель вида */}
            <HStack gap="0.5" bg="gray.100" borderRadius="lg" p="0.5" flexShrink="0" _dark={{ bg: 'gray.800' }}>
                <IconButton
                    aria-label="Сетка"
                    size="sm"
                    variant={view === 'grid' ? 'solid' : 'ghost'}
                    colorPalette={view === 'grid' ? 'pink' : 'gray'}
                    borderRadius="md"
                    onClick={() => onViewChange('grid')}
                >
                    <LuGrid2X2 size={14} />
                </IconButton>
                <IconButton
                    aria-label="Список"
                    size="sm"
                    variant={view === 'list' ? 'solid' : 'ghost'}
                    colorPalette={view === 'list' ? 'pink' : 'gray'}
                    borderRadius="md"
                    onClick={() => onViewChange('list')}
                >
                    <LuLayoutList size={14} />
                </IconButton>
            </HStack>
        </Flex>
    );
}
