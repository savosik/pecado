import { useCallback } from 'react';
import { Box, HStack, Text, Badge, Stack, Collapsible } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { LuFolder, LuFolderOpen } from 'react-icons/lu';

/**
 * Рекурсивный узел дерева категорий с чекбоксом.
 */
const CategoryTreeNode = ({ category, level = 0, selectedIds, onToggle }) => {
    const hasChildren = category.children && category.children.length > 0;
    const isSelected = selectedIds.includes(category.id);

    return (
        <Box>
            <HStack
                py={1.5}
                px={2}
                pl={`${level * 28 + 8}px`}
                _hover={{ bg: 'bg.subtle' }}
                borderRadius="md"
                cursor="pointer"
                onClick={() => onToggle(category.id)}
            >
                {/* Category Icon */}
                <Box color="fg.muted" flexShrink={0}>
                    {hasChildren ? <LuFolderOpen size={18} /> : <LuFolder size={18} />}
                </Box>

                {/* Checkbox */}
                <Checkbox
                    checked={isSelected}
                    onCheckedChange={() => onToggle(category.id)}
                    onClick={(e) => e.stopPropagation()}
                    colorPalette="blue"
                    size="sm"
                />

                {/* Name */}
                <Text
                    fontSize="sm"
                    fontWeight={isSelected ? 'semibold' : 'normal'}
                    color={isSelected ? 'blue.fg' : undefined}
                >
                    {category.name}
                </Text>

                {category.products_count !== undefined && (
                    <Badge size="xs" variant="subtle" colorPalette={category.products_count > 0 ? 'blue' : 'gray'}>
                        {category.products_count} тов.
                    </Badge>
                )}
            </HStack>

            {hasChildren && (
                <Box>
                    {category.children.map(child => (
                        <CategoryTreeNode
                            key={child.id}
                            category={child}
                            level={level + 1}
                            selectedIds={selectedIds}
                            onToggle={onToggle}
                        />
                    ))}
                </Box>
            )}
        </Box>
    );
};

/**
 * Компонент выбора категорий в виде иерархического дерева с чекбоксами.
 * Дерево всегда полностью раскрыто.
 *
 * @param {Array} categoryTree - дерево категорий (результат toTree())
 * @param {Array} value - массив выбранных ID категорий
 * @param {Function} onChange - callback(newSelectedIds)
 */
export function CategoryTreeSelector({ categoryTree = [], value = [], onChange }) {
    const handleToggle = useCallback((id) => {
        const newSelected = value.includes(id)
            ? value.filter(v => v !== id)
            : [...value, id];
        onChange(newSelected);
    }, [value, onChange]);

    if (!categoryTree || categoryTree.length === 0) {
        return (
            <Box p={8} textAlign="center" color="fg.muted">
                Категории не найдены
            </Box>
        );
    }

    return (
        <Box borderWidth="1px" borderRadius="lg" overflow="hidden" bg="bg.panel" maxH="600px" overflowY="auto">
            {categoryTree.map(category => (
                <CategoryTreeNode
                    key={category.id}
                    category={category}
                    selectedIds={value}
                    onToggle={handleToggle}
                />
            ))}
        </Box>
    );
}
