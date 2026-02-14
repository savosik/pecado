import { useState } from 'react';
import { Box, Badge } from '@chakra-ui/react';

/**
 * Список тегов товара с ограничением видимых + кнопка «ещё».
 *
 * @param {{ tags: Array, maxVisible?: number }} props
 */
export default function TagList({ tags, maxVisible = 2 }) {
    const [showAll, setShowAll] = useState(false);

    if (!tags || tags.length === 0) return null;

    const visible = showAll ? tags : tags.slice(0, maxVisible);
    const remaining = tags.length - maxVisible;

    return (
        <Box display="flex" flexWrap="wrap" gap="1">
            {visible.map((tag) => (
                <Badge
                    key={tag.id}
                    variant="subtle"
                    colorPalette="gray"
                    fontSize="2xs"
                    fontWeight="500"
                    borderRadius="sm"
                    px="1.5"
                    py="0"
                    textTransform="none"
                >
                    {tag.name}
                </Badge>
            ))}
            {!showAll && remaining > 0 && (
                <Box
                    as="button"
                    fontSize="2xs"
                    color="gray.400"
                    _hover={{ color: 'gray.600' }}
                    transition="color 0.15s"
                    px="1"
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        setShowAll(true);
                    }}
                    title={`Ещё ${remaining} тегов`}
                >
                    +{remaining}
                </Box>
            )}
            {showAll && remaining > 0 && (
                <Box
                    as="button"
                    fontSize="2xs"
                    color="gray.400"
                    _hover={{ color: 'gray.600' }}
                    transition="color 0.15s"
                    px="1"
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        setShowAll(false);
                    }}
                    title="Скрыть"
                >
                    скрыть
                </Box>
            )}
        </Box>
    );
}
