import { HStack, Tag, Text } from '@chakra-ui/react';
import { LuTag } from 'react-icons/lu';

/**
 * Фильтр по тегам с множественным выбором.
 *
 * @param {{
 *   tags: string[],
 *   selectedTags: string[],
 *   onToggle: (tag: string) => void,
 *   onReset: () => void,
 * }} props
 */
export default function ContentTagFilter({ tags = [], selectedTags = [], onToggle, onReset }) {
    if (!tags.length) return null;

    const selectedSet = new Set(selectedTags);

    return (
        <HStack gap="2" mb="6" flexWrap="wrap" align="center">
            <HStack gap="1" color="fg.muted" flexShrink={0}>
                <LuTag size={14} />
                <Text fontSize="xs" fontWeight="medium" textTransform="uppercase" letterSpacing="0.04em">
                    Теги
                </Text>
            </HStack>
            {tags.map((tag) => {
                const isSelected = selectedSet.has(tag);
                return (
                    <Tag.Root
                        key={tag}
                        size="md"
                        variant={isSelected ? 'solid' : 'outline'}
                        colorPalette={isSelected ? 'pecado' : 'gray'}
                        cursor="pointer"
                        onClick={() => onToggle(tag)}
                        transition="all 0.15s"
                        _hover={{
                            borderColor: isSelected ? undefined : 'pecado.300',
                            shadow: 'xs',
                        }}
                        userSelect="none"
                    >
                        <Tag.Label>{tag}</Tag.Label>
                    </Tag.Root>
                );
            })}
            {selectedTags.length > 0 && (
                <Tag.Root
                    size="md"
                    variant="subtle"
                    colorPalette="gray"
                    cursor="pointer"
                    onClick={onReset}
                    _hover={{ bg: 'gray.200' }}
                    userSelect="none"
                >
                    <Tag.Label>Сбросить</Tag.Label>
                </Tag.Root>
            )}
        </HStack>
    );
}
