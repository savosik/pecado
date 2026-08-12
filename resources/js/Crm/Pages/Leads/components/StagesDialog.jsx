import { useEffect, useState } from 'react';
import axios from 'axios';
import { Box, Dialog, HStack, IconButton, Input, Portal, Text, VStack } from '@chakra-ui/react';
import { LuArrowDown, LuArrowUp, LuPlus, LuTrash2 } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { Tooltip } from '@/components/ui/tooltip';
import { toastError, toastSuccess } from '@/utils/toast';

const COLORS = [
    { value: 'gray', label: 'Серый' },
    { value: 'blue', label: 'Синий' },
    { value: 'teal', label: 'Бирюзовый' },
    { value: 'green', label: 'Зелёный' },
    { value: 'yellow', label: 'Жёлтый' },
    { value: 'orange', label: 'Оранжевый' },
    { value: 'red', label: 'Красный' },
    { value: 'purple', label: 'Фиолетовый' },
];

/**
 * Настройка воронки: состав, порядок, цвета и флаги стадий.
 *
 * Стадии — пользовательские данные, а не enum в коде, и менять их должно быть
 * можно без релиза. Доступно только тому, у кого есть право на состав воронки:
 * менеджер двигает лида, но воронку отдела не переписывает.
 *
 * Порядок задаётся стрелками, а не перетаскиванием: колонок обычно 5–8,
 * список короткий, и стрелки надёжнее — в модальном окне поверх доски,
 * которая сама на drag-and-drop, второй DnD путал бы обработчики.
 */
export default function StagesDialog({ open, stages = [], onClose, onSaved }) {
    const [rows, setRows] = useState([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (open) {
            setRows(stages.map((stage) => ({ ...stage })));
            setError(null);
        }
    }, [open, stages]);

    const patch = (id, changes) => setRows((prev) => prev.map((row) => (
        row.id === id ? { ...row, ...changes } : row
    )));

    const save = async (row, changes) => {
        patch(row.id, changes);

        try {
            await axios.patch(route('crm.lead-stages.update', row.id), { ...row, ...changes });
            onSaved?.();
        } catch (requestError) {
            toastError(requestError.response?.data?.message ?? 'Не удалось сохранить стадию.');
        }
    };

    /**
     * Флаги «выигрыш» и «проигрыш» взаимоисключающие: стадия не может быть
     * одновременно и тем и другим, а конверсия считается именно по ним.
     */
    const toggleOutcome = (row, field) => {
        const next = ! row[field];
        const other = field === 'is_won' ? 'is_lost' : 'is_won';

        save(row, { [field]: next, ...(next ? { [other]: false } : {}) });
    };

    const move = async (index, direction) => {
        const target = index + direction;

        if (target < 0 || target >= rows.length) return;

        const reordered = [...rows];
        [reordered[index], reordered[target]] = [reordered[target], reordered[index]];

        // Позиции пересчитываем подряд: держать в базе разрежённые значения
        // и надеяться на промежутки — способ однажды получить две стадии
        // с одинаковым порядком.
        const withPositions = reordered.map((row, i) => ({ ...row, position: i + 1 }));

        setRows(withPositions);
        setBusy(true);

        try {
            await Promise.all(withPositions.map((row) => axios.patch(
                route('crm.lead-stages.update', row.id),
                row,
            )));
            onSaved?.();
        } catch {
            toastError('Не удалось изменить порядок стадий.');
        } finally {
            setBusy(false);
        }
    };

    const add = async () => {
        setBusy(true);
        setError(null);

        try {
            await axios.post(route('crm.lead-stages.store'), {
                name: 'Новая стадия',
                color: 'gray',
            });
            toastSuccess('Стадия добавлена.');
            onSaved?.();
        } catch {
            toastError('Не удалось добавить стадию.');
        } finally {
            setBusy(false);
        }
    };

    const remove = async (row) => {
        setError(null);

        try {
            await axios.delete(route('crm.lead-stages.destroy', row.id));
            toastSuccess('Стадия удалена.');
            onSaved?.();
        } catch (requestError) {
            // Стадию с лидами удалить нельзя — сервер объясняет, сколько их
            // и что делать. Показываем это объяснение, а не «ошибка удаления».
            setError(
                requestError.response?.data?.errors?.stage?.[0]
                ?? requestError.response?.data?.message
                ?? 'Не удалось удалить стадию.',
            );
        }
    };

    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => ! isOpen && onClose()}
            size="xl"
            scrollBehavior="inside"
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Настройка воронки</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={3}>
                                <Text fontSize="xs" color="fg.muted">
                                    Порядок стадий — слева направо на доске. Флаги «выигрыш»
                                    и «проигрыш» задают, по каким стадиям считается конверсия:
                                    по ним, а не по последней колонке, поэтому добавление стадии
                                    в конец не ломает отчёт.
                                </Text>

                                {error && (
                                    <Box borderWidth="1px" borderColor="red.emphasized" borderRadius="md" p={2}>
                                        <Text fontSize="sm" color="red.fg">{error}</Text>
                                    </Box>
                                )}

                                {rows.map((row, index) => (
                                    <HStack key={row.id} gap={2} align="center" borderWidth="1px" borderRadius="md" p={2}>
                                        <VStack gap={0}>
                                            <IconButton
                                                size="2xs"
                                                variant="ghost"
                                                aria-label="Выше"
                                                disabled={index === 0 || busy}
                                                onClick={() => move(index, -1)}
                                            >
                                                <LuArrowUp />
                                            </IconButton>
                                            <IconButton
                                                size="2xs"
                                                variant="ghost"
                                                aria-label="Ниже"
                                                disabled={index === rows.length - 1 || busy}
                                                onClick={() => move(index, 1)}
                                            >
                                                <LuArrowDown />
                                            </IconButton>
                                        </VStack>

                                        <Input
                                            size="sm"
                                            flex="1"
                                            value={row.name}
                                            onChange={(event) => patch(row.id, { name: event.target.value })}
                                            onBlur={(event) => save(row, { name: event.target.value })}
                                        />

                                        <Box minW="130px">
                                            <NativeSelectRoot size="sm">
                                                <NativeSelectField
                                                    value={row.color}
                                                    onChange={(event) => save(row, { color: event.target.value })}
                                                >
                                                    {COLORS.map((color) => (
                                                        <option key={color.value} value={color.value}>{color.label}</option>
                                                    ))}
                                                </NativeSelectField>
                                            </NativeSelectRoot>
                                        </Box>

                                        <Tooltip content="Лиды на этой стадии считаются выигранными" openDelay={400}>
                                            <Checkbox
                                                size="sm"
                                                checked={!! row.is_won}
                                                onCheckedChange={() => toggleOutcome(row, 'is_won')}
                                            >
                                                Выигрыш
                                            </Checkbox>
                                        </Tooltip>

                                        <Tooltip content="Лиды на этой стадии считаются проигранными" openDelay={400}>
                                            <Checkbox
                                                size="sm"
                                                checked={!! row.is_lost}
                                                onCheckedChange={() => toggleOutcome(row, 'is_lost')}
                                            >
                                                Проигрыш
                                            </Checkbox>
                                        </Tooltip>

                                        <Tooltip content="Скрытая стадия не показывается на доске" openDelay={400}>
                                            <Checkbox
                                                size="sm"
                                                checked={row.is_active !== false}
                                                onCheckedChange={() => save(row, { is_active: row.is_active === false })}
                                            >
                                                На доске
                                            </Checkbox>
                                        </Tooltip>

                                        <IconButton
                                            size="xs"
                                            variant="ghost"
                                            colorPalette="red"
                                            aria-label="Удалить стадию"
                                            onClick={() => remove(row)}
                                        >
                                            <LuTrash2 />
                                        </IconButton>
                                    </HStack>
                                ))}

                                <Box>
                                    <Button size="sm" variant="outline" onClick={add} loading={busy}>
                                        <LuPlus /> Добавить стадию
                                    </Button>
                                </Box>
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <Button variant="ghost" onClick={onClose}>Закрыть</Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
