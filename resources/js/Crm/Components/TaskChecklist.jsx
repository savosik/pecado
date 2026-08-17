import { useEffect, useState } from 'react';
import axios from 'axios';
import { Box, HStack, IconButton, Input, Text, VStack } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import { toastError } from '@/utils/toast';

/**
 * Чек-лист внутри карточки задачи: добавление по Enter, отметка любым участником.
 *
 * Закрытие задачи с открытыми пунктами не блокируется — блокировка научила бы
 * отмечать галочки не глядя; предупреждение показывает диалог закрытия.
 */
export default function TaskChecklist({ taskId, items: initialItems, canEdit, onChanged }) {
    const [items, setItems] = useState(initialItems || []);
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setItems(initialItems || []);
    }, [initialItems]);

    // Строки списков несут только счётчики (items === null) — пункты догружаем сами.
    useEffect(() => {
        if (initialItems !== null && initialItems !== undefined) {
            return;
        }

        let cancelled = false;

        axios.get(`/crm/tasks/${taskId}`)
            .then(({ data }) => {
                if (!cancelled) {
                    setItems(data.checklist || []);
                }
            })
            .catch(() => {});

        return () => { cancelled = true; };
    }, [taskId, initialItems]);

    const sync = (data) => {
        setItems(data.items || []);
        onChanged?.(data);
    };

    const add = async () => {
        const title = draft.trim();

        if (!title || busy) {
            return;
        }

        setBusy(true);
        try {
            const res = await axios.post(`/crm/tasks/${taskId}/checklist`, { title });
            sync(res.data);
            setDraft('');
        } catch (e) {
            toastError('Пункт не добавлен', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const toggle = async (item) => {
        try {
            const res = await axios.patch(`/crm/tasks/${taskId}/checklist/${item.id}`, {
                is_done: !item.is_done,
            });
            sync(res.data);
        } catch (e) {
            toastError('Не удалось отметить пункт', e?.response?.data?.message || 'Попробуйте ещё раз.');
        }
    };

    const remove = async (item) => {
        try {
            const res = await axios.delete(`/crm/tasks/${taskId}/checklist/${item.id}`);
            sync(res.data);
        } catch (e) {
            toastError('Пункт не удалён', e?.response?.data?.message || 'Попробуйте ещё раз.');
        }
    };

    const done = items.filter((item) => item.is_done).length;

    return (
        <VStack align="stretch" gap={2}>
            <HStack justify="space-between">
                <Text fontSize="sm" fontWeight="600">Чек-лист</Text>
                {items.length > 0 && (
                    <Text fontSize="xs" color="fg.muted">{done}/{items.length}</Text>
                )}
            </HStack>

            {items.length > 0 && (
                <Box borderWidth="1px" borderRadius="md" h="4px" overflow="hidden" bg="bg.muted">
                    <Box
                        h="100%"
                        w={`${Math.round((done / items.length) * 100)}%`}
                        bg={done === items.length ? 'green.solid' : 'blue.solid'}
                        transition="width 0.2s"
                    />
                </Box>
            )}

            <VStack align="stretch" gap={1}>
                {items.map((item) => (
                    <HStack key={item.id} gap={2} justify="space-between" _hover={{ bg: 'bg.muted' }} borderRadius="sm" px={1}>
                        <Checkbox
                            size="sm"
                            checked={item.is_done}
                            onCheckedChange={() => toggle(item)}
                            disabled={!canEdit}
                        >
                            <Text
                                fontSize="sm"
                                textDecoration={item.is_done ? 'line-through' : undefined}
                                color={item.is_done ? 'fg.muted' : undefined}
                                title={item.done_by ? `Отметил(а) ${item.done_by.name} ${item.done_at_label || ''}` : undefined}
                            >
                                {item.title}
                            </Text>
                        </Checkbox>
                        {canEdit && (
                            <IconButton
                                size="2xs"
                                variant="ghost"
                                colorPalette="red"
                                aria-label="Удалить пункт"
                                onClick={() => remove(item)}
                            >
                                <LuTrash2 />
                            </IconButton>
                        )}
                    </HStack>
                ))}
            </VStack>

            {canEdit && (
                <HStack gap={2}>
                    <Input
                        size="sm"
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                add();
                            }
                        }}
                        placeholder="Новый пункт — Enter для добавления"
                    />
                    <IconButton size="sm" variant="outline" aria-label="Добавить пункт" onClick={add} disabled={busy || !draft.trim()}>
                        <LuPlus />
                    </IconButton>
                </HStack>
            )}
        </VStack>
    );
}
