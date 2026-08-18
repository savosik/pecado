import { useEffect, useRef, useState } from 'react';
import { Badge, Box, HStack, IconButton, Input, Text, VStack } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';

/**
 * Выбор нескольких сотрудников: выбранные — тегами, добавление — из
 * выпадающего списка с поиском. Без сторонних библиотек.
 */
export default function PeoplePicker({
    options = [],
    value = [],
    onChange,
    excludeId = null,
    placeholder = 'Найти сотрудника…',
    disabled = false,
}) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);

    // Клик мимо закрывает выпадашку.
    useEffect(() => {
        const onClickAway = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onClickAway);

        return () => document.removeEventListener('mousedown', onClickAway);
    }, []);

    const selected = options.filter((user) => value.includes(Number(user.id)));

    const available = options.filter((user) => {
        if (value.includes(Number(user.id)) || String(user.id) === String(excludeId ?? '')) {
            return false;
        }

        return !query.trim() || user.name.toLowerCase().includes(query.trim().toLowerCase());
    });

    const add = (user) => {
        onChange([...value, Number(user.id)]);
        setQuery('');
    };

    const remove = (id) => onChange(value.filter((item) => item !== Number(id)));

    return (
        <Box ref={rootRef} position="relative">
            {selected.length > 0 && (
                <HStack gap={1} flexWrap="wrap" mb={1}>
                    {selected.map((user) => (
                        <Badge key={user.id} variant="subtle" colorPalette="blue" py={0.5} pl={2} pr={1}>
                            <HStack gap={1}>
                                <Text fontSize="xs">{user.name}</Text>
                                {!disabled && (
                                    <IconButton
                                        size="2xs"
                                        variant="ghost"
                                        aria-label={`Убрать ${user.name}`}
                                        onClick={() => remove(user.id)}
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

            {!disabled && (
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
                        // Enter добавляет первого из выдачи — быстрый набор без мыши.
                        if (e.key === 'Enter') {
                            e.preventDefault();

                            if (available.length > 0) {
                                add(available[0]);
                            }
                        }

                        if (e.key === 'Escape') {
                            setOpen(false);
                        }
                    }}
                />
            )}

            {open && !disabled && available.length > 0 && (
                <Box
                    position="absolute"
                    zIndex="dropdown"
                    top="100%"
                    left={0}
                    right={0}
                    mt={1}
                    maxH="180px"
                    overflowY="auto"
                    borderWidth="1px"
                    borderRadius="md"
                    bg="bg.panel"
                    boxShadow="md"
                >
                    <VStack align="stretch" gap={0}>
                        {available.map((user) => (
                            <Box
                                key={user.id}
                                px={3}
                                py={1.5}
                                cursor="pointer"
                                _hover={{ bg: 'bg.muted' }}
                                onClick={() => add(user)}
                            >
                                <Text fontSize="sm">{user.name}</Text>
                            </Box>
                        ))}
                    </VStack>
                </Box>
            )}
        </Box>
    );
}
