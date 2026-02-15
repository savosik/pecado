import { Box, Flex, Text } from '@chakra-ui/react';
import { LuCheck } from 'react-icons/lu';

/**
 * CategoryFilter — фильтр по категориям с чекбоксами и счётчиками.
 *
 * Отображает плоский список категорий из фасетных данных.
 * Категории с count=0 скрываются, кроме уже выбранных.
 *
 * @param {{
 *   categories: Array<{ id: number, name: string, slug: string, count: number }>,
 *   selectedIds: number[],
 *   onChange: (ids: number[]) => void,
 * }} props
 */
export default function CategoryFilter({ categories = [], selectedIds = [], onChange }) {
    const selectedSet = new Set(selectedIds.map(Number));

    // Показываем только категории с count > 0 или уже выбранные
    const visible = categories.filter(
        (cat) => cat.count > 0 || selectedSet.has(cat.id)
    );

    if (visible.length === 0) {
        return (
            <Text fontSize="xs" color="gray.400">
                Нет доступных категорий
            </Text>
        );
    }

    const handleToggle = (id) => {
        const numId = Number(id);
        if (selectedSet.has(numId)) {
            onChange(selectedIds.filter((v) => Number(v) !== numId));
        } else {
            onChange([...selectedIds, numId]);
        }
    };

    return (
        <Flex direction="column" gap="0.5">
            {visible.map((cat) => {
                const isChecked = selectedSet.has(cat.id);

                return (
                    <Flex
                        key={cat.id}
                        as="button"
                        type="button"
                        align="center"
                        gap="2.5"
                        px="2"
                        py="1.5"
                        borderRadius="md"
                        cursor="pointer"
                        transition="all 0.15s"
                        bg={isChecked ? 'pink.50' : 'transparent'}
                        _dark={{ bg: isChecked ? 'pink.900/30' : 'transparent' }}
                        _hover={{
                            bg: isChecked ? 'pink.100' : 'gray.50',
                            _dark: { bg: isChecked ? 'pink.900/40' : 'gray.700' },
                        }}
                        onClick={() => handleToggle(cat.id)}
                    >
                        {/* Custom checkbox */}
                        <Box
                            w="16px"
                            h="16px"
                            borderRadius="sm"
                            border="2px solid"
                            borderColor={isChecked ? 'pink.500' : 'gray.300'}
                            _dark={{ borderColor: isChecked ? 'pink.400' : 'gray.600' }}
                            bg={isChecked ? 'pink.500' : 'transparent'}
                            _darkBg={isChecked ? 'pink.400' : 'transparent'}
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
                                color={isChecked ? 'pink.600' : 'gray.700'}
                                _dark={{ color: isChecked ? 'pink.300' : 'gray.300' }}
                                fontWeight={isChecked ? '500' : '400'}
                                textAlign="left"
                            >
                                {cat.name}
                            </Text>
                            <Text
                                fontSize="xs"
                                color="gray.400"
                                _dark={{ color: 'gray.500' }}
                                flexShrink="0"
                            >
                                {cat.count}
                            </Text>
                        </Flex>
                    </Flex>
                );
            })}
        </Flex>
    );
}
