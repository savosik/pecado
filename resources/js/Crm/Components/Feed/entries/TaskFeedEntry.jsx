import { useState } from 'react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import TaskListItem from '@/Crm/Components/TaskListItem';
import TaskCloseDialog from '@/Crm/Components/TaskCloseDialog';
import { usePermission } from '@/shared/Panel/usePermission';

/**
 * Задача в ленте.
 *
 * Показывается тем же `TaskListItem`, что в разделе задач — второй способ рисовать
 * задачу означал бы, что просроченность выглядит по-разному в двух местах.
 *
 * Закрыть задачу можно прямо здесь: это ровно тот момент, когда менеджер о ней
 * думает. Полное редактирование остаётся в разделе задач.
 */
export default function TaskFeedEntry({ entry, onChanged }) {
    const { can } = usePermission();
    const [closing, setClosing] = useState(false);
    const task = entry.task;

    if (!task) return null;

    const canClose = can('crm-tasks.edit') && task.can?.update && task.status !== 'done' && task.status !== 'canceled';

    return (
        <Box>
            <HStack gap={2} mb={1} flexWrap="wrap">
                <Badge colorPalette="purple" variant="subtle" size="sm">Задача</Badge>
                <Text fontSize="xs" color="fg.muted">
                    поставил {entry.author?.name}, {entry.happened_at_label}
                </Text>
            </HStack>

            <VStack align="stretch" gap={2}>
                <TaskListItem task={task} showEntity />

                {canClose && (
                    <HStack>
                        <Button size="xs" variant="outline" onClick={() => setClosing(true)}>
                            Закрыть с отчётом
                        </Button>
                    </HStack>
                )}
            </VStack>

            {/* Диалог открывается самим наличием задачи, отдельного `open` у него нет. */}
            <TaskCloseDialog
                task={closing ? task : null}
                onClose={() => setClosing(false)}
                onClosed={() => {
                    setClosing(false);
                    onChanged?.();
                }}
            />
        </Box>
    );
}
