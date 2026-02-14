import { useCallback, useMemo } from 'react';
import { Box, Text, Badge } from '@chakra-ui/react';
import { LuFolder, LuFolderOpen, LuCircle, LuCircleDot } from 'react-icons/lu';

/**
 * Строит карту id → ancestors для быстрого получения пути.
 */
function buildAncestorMap(tree, parentPath = []) {
    const map = {};
    for (const node of tree) {
        map[node.id] = parentPath;
        if (node.children?.length) {
            Object.assign(map, buildAncestorMap(node.children, [...parentPath, node.name]));
        }
    }
    return map;
}

/**
 * Рекурсивный узел дерева категорий с radio-кнопкой (одиночный выбор).
 */
const CategoryTreeNode = ({ category, level = 0, selectedId, onSelect }) => {
    const hasChildren = category.children && category.children.length > 0;
    const isSelected = selectedId === category.id;

    return (
        <Box>
            <HStack
                py={1.5}
                px={2}
                pl={`${level * 28 + 8}px`}
                _hover={{ bg: 'bg.subtle' }}
                borderRadius="md"
                cursor="pointer"
                onClick={() => onSelect(isSelected ? null : category.id)}
                bg={isSelected ? 'blue.50' : undefined}
            >
                {/* Category Icon */}
                <Box color="fg.muted" flexShrink={0}>
                    {hasChildren ? <LuFolderOpen size={18} /> : <LuFolder size={18} />}
                </Box>

                {/* Radio indicator */}
                <Box color={isSelected ? 'blue.500' : 'fg.muted'} flexShrink={0}>
                    {isSelected ? <LuCircleDot size={18} /> : <LuCircle size={18} />}
                </Box>

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
                            selectedId={selectedId}
                            onSelect={onSelect}
                        />
                    ))}
                </Box>
            )}
        </Box>
    );
};

/**
 * Находит категорию по id в дереве рекурсивно.
 */
function findCategoryInTree(tree, id) {
    for (const node of tree) {
        if (node.id === id) return node;
        if (node.children?.length) {
            const found = findCategoryInTree(node.children, id);
            if (found) return found;
        }
    }
    return null;
}

/**
 * Компонент выбора одной категории в виде иерархического дерева с radio-кнопками.
 * Дерево всегда полностью раскрыто.
 *
 * @param {Array} categoryTree - дерево категорий (результат toTree())
 * @param {number|null} value - ID выбранной категории или null
 * @param {Function} onChange - callback(categoryId | null)
 */
export function CategoryTreeSelector({ categoryTree = [], value = null, onChange }) {
    const handleSelect = useCallback((id) => {
        onChange(id);
    }, [onChange]);

    // Строим путь для выбранной категории
    const ancestorMap = useMemo(() => buildAncestorMap(categoryTree), [categoryTree]);

    const selectedCategory = useMemo(() => {
        if (!value) return null;
        return findCategoryInTree(categoryTree, value);
    }, [categoryTree, value]);

    const selectedPath = useMemo(() => {
        if (!value || !selectedCategory) return null;
        const ancestors = ancestorMap[value] || [];
        return [...ancestors, selectedCategory.name].join(' → ');
    }, [value, selectedCategory, ancestorMap]);

    if (!categoryTree || categoryTree.length === 0) {
        return (
            <Box p={8} textAlign="center" color="fg.muted">
                Категории не найдены
            </Box>
        );
    }

    return (
        <Box>
            {/* Выбранная категория — путь */}
            {selectedPath && (
                <Box mb={3} p={3} bg="blue.50" borderRadius="lg" borderWidth="1px" borderColor="blue.200">
                    <Text fontSize="sm" color="blue.700" fontWeight="medium">
                        Выбрана: {selectedPath}
                    </Text>
                </Box>
            )}

            <Box borderWidth="1px" borderRadius="lg" overflow="hidden" bg="bg.panel" maxH="600px" overflowY="auto">
                {categoryTree.map(category => (
                    <CategoryTreeNode
                        key={category.id}
                        category={category}
                        selectedId={value}
                        onSelect={handleSelect}
                    />
                ))}
            </Box>
        </Box>
    );
}
