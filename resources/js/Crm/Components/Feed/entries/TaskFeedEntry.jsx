import { useState } from 'react';
import { Badge, HStack, Text } from '@chakra-ui/react';
import { LuCheck, LuPaperclip } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import TaskCloseDialog from '@/Crm/Components/TaskCloseDialog';
import { usePermission } from '@/shared/Panel/usePermission';
import FeedEntryShell from '../FeedEntryShell';

/**
 * Задача в ленте.
 *
 * Закрыть можно прямо здесь: это ровно тот момент, когда менеджер о задаче думает.
 * Полное редактирование остаётся в разделе задач — превращать ленту во второй
 * интерфейс правки незачем.
 *
 * Просроченная задача краснеет всей полосой, а не одним бейджем: в ленте на два
 * экрана бейдж теряется, полоса — нет.
 */
export default function TaskFeedEntry({ entry, onChanged }) {
    const { can } = usePermission();
    const [closing, setClosing] = useState(false);
    const task = entry.task;

    if (!task) return null;

    const finished = task.status === 'done' || task.status === 'canceled';
    const canClose = can('crm-tasks.edit') && task.can?.update && !finished;

    const badges = (
        <>
            <Badge colorPalette={task.status_color} variant="subtle" size="sm">
                {task.status_label}
            </Badge>
            {task.is_overdue && (
                <Badge colorPalette="red" variant="solid" size="sm">Просрочена</Badge>
            )}
            {task.priority !== 'normal' && (
                <Badge colorPalette={task.priority_color} variant="subtle" size="sm">
                    {task.priority_label}
                </Badge>
            )}
            {task.entity && (
                <Badge colorPalette="gray" variant="outline" size="sm">
                    {task.entity.url
                        ? <a href={task.entity.url}>{task.entity.title}</a>
                        : task.entity.title}
                </Badge>
            )}
        </>
    );

    return (
        <>
            <FeedEntryShell
                type="task"
                author={entry.author?.name}
                time={entry.happened_at_label}
                title={task.title}
                badges={badges}
                muted={finished}
                accent={task.is_overdue ? 'red' : null}
                actions={canClose ? (
                    <Button size="xs" variant="ghost" colorPalette="green" onClick={() => setClosing(true)} title="Закрыть с отчётом">
                        <LuCheck />
                    </Button>
                ) : null}
            >
                <HStack gap={3} flexWrap="wrap">
                    {task.due_at_label && (
                        <Text fontSize="xs" color={task.is_overdue ? 'red.500' : 'fg.muted'}>
                            Срок: {task.due_at_label}
                        </Text>
                    )}
                    <Text fontSize="xs" color="fg.muted">Исполнитель: {task.assignee?.name}</Text>
                    {task.attachments_count > 0 && (
                        <HStack gap={1} color="fg.muted">
                            <LuPaperclip size={12} />
                            <Text fontSize="xs">{task.attachments_count}</Text>
                        </HStack>
                    )}
                </HStack>

                {task.description && (
                    <Text fontSize="sm" color="fg.muted" whiteSpace="pre-wrap">{task.description}</Text>
                )}
            </FeedEntryShell>

            {/* Диалог открывается самим наличием задачи, отдельного `open` у него нет. */}
            <TaskCloseDialog
                task={closing ? task : null}
                onClose={() => setClosing(false)}
                onClosed={() => {
                    setClosing(false);
                    onChanged?.();
                }}
            />
        </>
    );
}
