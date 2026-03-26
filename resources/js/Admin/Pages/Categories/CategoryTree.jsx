import { useState } from 'react';
import { Box, HStack, IconButton, Text, Image, Badge, Collapsible } from '@chakra-ui/react';
import { LuChevronRight, LuChevronDown, LuPencil, LuTrash2, LuFolder, LuFolderOpen } from 'react-icons/lu';
import { router } from '@inertiajs/react';

const CategoryNode = ({ category, level = 0, onDelete }) => {
    const [isOpen, setIsOpen] = useState(false);
    const hasChildren = category.children && category.children.length > 0;

    const handleToggle = (e) => {
        e.stopPropagation();
        setIsOpen(!isOpen);
    };

    return (
        <Box>
            <HStack
                p={2}
                pl={`${level * 24}px`}
                _hover={{ bg: 'bg.subtle' }}
                borderRadius="md"
                justify="space-between"
                role="group"
            >
                <HStack gap={3} flex={1}>
                    {/* Toggle Button / Spacer */}
                    <Box w="24px" display="flex" justifyContent="center">
                        {hasChildren && (
                            <IconButton
                                size="xs"
                                variant="ghost"
                                onClick={handleToggle}
                                aria-label={isOpen ? "Свернуть" : "Развернуть"}
                            >
                                {isOpen ? <LuChevronDown /> : <LuChevronRight />}
                            </IconButton>
                        )}
                    </Box>

                    {/* Category Icon */}
                    {category.media && category.media.length > 0 ? (
                        <Image
                            src={category.media[0].original_url}
                            alt={category.name}
                            boxSize="24px"
                            objectFit="cover"
                            borderRadius="sm"
                        />
                    ) : (
                        <Box color="fg.muted">
                            {isOpen ? <LuFolderOpen size={20} /> : <LuFolder size={20} />}
                        </Box>
                    )}

                    {/* Name & ID */}
                    <HStack gap={2}>
                        <Text fontWeight="medium" fontSize="sm">
                            {category.name}
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            #{category.id}
                        </Text>
                        {category.external_id && (
                            <Badge size="xs" variant="outline" colorPalette="gray">
                                {category.external_id}
                            </Badge>
                        )}
                        <Badge size="xs" variant="subtle" colorPalette={category.products_count > 0 ? 'blue' : 'gray'}>
                            {category.products_count ?? 0} тов.
                        </Badge>
                    </HStack>
                </HStack>

                {/* Actions */}
                <HStack gap={1}>
                    <IconButton
                        size="xs"
                        variant="ghost"
                        aria-label="Редактировать"
                        onClick={() => router.visit(route('admin.categories.edit', category.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="xs"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить"
                        onClick={() => onDelete(category)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            </HStack>

            {hasChildren && (
                <Collapsible.Root open={isOpen}>
                    <Collapsible.Content>
                        <Box>
                            {category.children.map(child => (
                                <CategoryNode
                                    key={child.id}
                                    category={child}
                                    level={level + 1}
                                    onDelete={onDelete}
                                />
                            ))}
                        </Box>
                    </Collapsible.Content>
                </Collapsible.Root>
            )}
        </Box>
    );
};

export default function CategoryTree({ data, onDelete }) {
    if (!data || data.length === 0) {
        return (
            <Box p={8} textAlign="center" color="fg.muted">
                Категории не найдены
            </Box>
        );
    }

    return (
        <Box borderWidth="1px" borderRadius="lg" overflow="hidden" bg="bg.panel">
            {data.map(category => (
                <CategoryNode
                    key={category.id}
                    category={category}
                    onDelete={onDelete}
                />
            ))}
        </Box>
    );
}
