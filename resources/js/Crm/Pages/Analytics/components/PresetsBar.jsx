import { useState } from 'react';
import {
    Box, HStack, Text, Wrap, WrapItem, Input, VStack, IconButton, Popover, Portal,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuBookmark, LuBookmarkPlus, LuX } from 'react-icons/lu';

/**
 * Полоса личных пресетов фильтров под шапкой отчёта: клик по пресету применяет
 * сохранённый набор фильтров, крестик удаляет его, кнопка справа сохраняет
 * текущие фильтры под именем.
 */
export default function PresetsBar({ presets = [], onApply, onDelete, onSave, loading = false }) {
    const [name, setName] = useState('');
    const [open, setOpen] = useState(false);

    const save = () => {
        const trimmed = name.trim();
        if (!trimmed) return;
        onSave(trimmed);
        setName('');
        setOpen(false);
    };

    return (
        <Box
            bg="bg.panel"
            borderWidth="1px"
            borderColor="border"
            borderRadius="xl"
            px={3}
            py={2}
            mb={4}
        >
            <HStack justify="space-between" gap={3} wrap="wrap" align="center">
                <HStack gap={2} flex="1" minW="0" wrap="wrap">
                    <HStack gap={1} color="fg.muted" flexShrink={0}>
                        <LuBookmark size={15} />
                        <Text fontSize="sm" fontWeight="600">Мои фильтры</Text>
                    </HStack>
                    {presets.length === 0 ? (
                        <Text fontSize="sm" color="fg.muted">— сохраните текущие фильтры, чтобы быстро вернуться к ним</Text>
                    ) : (
                        <Wrap gap={1.5}>
                            {presets.map((p) => (
                                <WrapItem key={p.id}>
                                    <HStack
                                        gap={0}
                                        borderWidth="1px"
                                        borderColor="border"
                                        borderRadius="full"
                                        bg="bg"
                                        overflow="hidden"
                                    >
                                        <Box
                                            as="button"
                                            type="button"
                                            onClick={() => onApply(p)}
                                            pl={3}
                                            pr={2}
                                            py={1}
                                            fontSize="sm"
                                            _hover={{ bg: 'bg.muted' }}
                                            title="Применить пресет"
                                        >
                                            <Text lineClamp={1} maxW="200px">{p.name}</Text>
                                        </Box>
                                        <IconButton
                                            size="2xs"
                                            variant="ghost"
                                            colorPalette="red"
                                            borderRadius="none"
                                            onClick={() => onDelete(p.id)}
                                            aria-label={`Удалить пресет: ${p.name}`}
                                        >
                                            <LuX />
                                        </IconButton>
                                    </HStack>
                                </WrapItem>
                            ))}
                        </Wrap>
                    )}
                </HStack>

                <Popover.Root open={open} onOpenChange={(e) => setOpen(e.open)} positioning={{ placement: 'bottom-end' }}>
                    <Popover.Trigger asChild>
                        <Button size="xs" variant="outline" flexShrink={0} disabled={loading}>
                            <LuBookmarkPlus /> Сохранить фильтры
                        </Button>
                    </Popover.Trigger>
                    <Portal>
                        <Popover.Positioner>
                            <Popover.Content>
                                <Popover.Body p={3}>
                                    <VStack align="stretch" gap={2}>
                                        <Text fontSize="sm" fontWeight="600">Сохранить текущие фильтры</Text>
                                        <Input
                                            size="sm"
                                            placeholder="Название пресета"
                                            value={name}
                                            maxLength={80}
                                            onChange={(e) => setName(e.target.value)}
                                            onKeyDown={(e) => { if (e.key === 'Enter') save(); }}
                                            autoFocus
                                        />
                                        <Button size="sm" colorPalette="red" onClick={save} disabled={!name.trim()}>
                                            Сохранить
                                        </Button>
                                    </VStack>
                                </Popover.Body>
                            </Popover.Content>
                        </Popover.Positioner>
                    </Portal>
                </Popover.Root>
            </HStack>
        </Box>
    );
}
