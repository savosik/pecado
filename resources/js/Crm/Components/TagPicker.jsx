import { useEffect, useRef, useState } from 'react';
import { Badge, Box, HStack, IconButton, Input, Text, VStack } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';

/**
 * Теги задачи: выбранные — чипами, ввод с подсказками из уже существующих.
 * Enter добавляет — подсказку, если она есть, иначе свободный текст.
 */
export default function TagPicker({
    value = [],
    onChange,
    suggestions = [],
    placeholder = 'Добавить тег…',
    disabled = false,
    max = 10,
}) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);

    useEffect(() => {
        const onClickAway = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onClickAway);

        return () => document.removeEventListener('mousedown', onClickAway);
    }, []);

    const lower = value.map((tag) => tag.toLowerCase());
    const matching = suggestions.filter((tag) => {
        if (lower.includes(tag.toLowerCase())) {
            return false;
        }

        return !query.trim() || tag.toLowerCase().includes(query.trim().toLowerCase());
    });

    const add = (tag) => {
        const clean = tag.trim().slice(0, 50);

        if (!clean || lower.includes(clean.toLowerCase()) || value.length >= max) {
            return;
        }

        onChange([...value, clean]);
        setQuery('');
    };

    const remove = (tag) => onChange(value.filter((item) => item !== tag));

    return (
        <Box ref={rootRef} position="relative">
            {value.length > 0 && (
                <HStack gap={1} flexWrap="wrap" mb={1}>
                    {value.map((tag) => (
                        <Badge key={tag} variant="subtle" colorPalette="purple" py={0.5} pl={2} pr={1}>
                            <HStack gap={1}>
                                <Text fontSize="xs">{tag}</Text>
                                {!disabled && (
                                    <IconButton
                                        size="2xs"
                                        variant="ghost"
                                        aria-label={`Убрать тег ${tag}`}
                                        onClick={() => remove(tag)}
                                        minW="auto"
                                        h="auto"
                                    >
                                        <LuX size={11} />
                                    </IconButton>
                                )}
                            </HStack>
                        </Badge>
                    ))}
                </HStack>
            )}

            {!disabled && value.length < max && (
                <Input
                    size="sm"
                    value={query}
                    placeholder={placeholder}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            // Подсказка приоритетнее свободного текста: меньше
                            // «Оплата»/«оплата»-дублей в справочнике.
                            add(matching.length > 0 && query.trim() ? matching[0] : query);
                        }

                        if (e.key === 'Escape') {
                            setOpen(false);
                        }
                    }}
                />
            )}

            {open && !disabled && (matching.length > 0 || query.trim()) && (
                <Box
                    position="absolute"
                    zIndex="dropdown"
                    top="100%"
                    left={0}
                    right={0}
                    mt={1}
                    maxH="160px"
                    overflowY="auto"
                    borderWidth="1px"
                    borderRadius="md"
                    bg="bg.panel"
                    boxShadow="md"
                >
                    <VStack align="stretch" gap={0}>
                        {matching.map((tag) => (
                            <Box
                                key={tag}
                                px={3}
                                py={1.5}
                                cursor="pointer"
                                _hover={{ bg: 'bg.muted' }}
                                onClick={() => add(tag)}
                            >
                                <Text fontSize="sm">{tag}</Text>
                            </Box>
                        ))}
                        {query.trim() && !matching.some((tag) => tag.toLowerCase() === query.trim().toLowerCase()) && (
                            <Box
                                px={3}
                                py={1.5}
                                cursor="pointer"
                                _hover={{ bg: 'bg.muted' }}
                                onClick={() => add(query)}
                            >
                                <Text fontSize="sm" color="fg.muted">Создать тег «{query.trim()}»</Text>
                            </Box>
                        )}
                    </VStack>
                </Box>
            )}
        </Box>
    );
}
