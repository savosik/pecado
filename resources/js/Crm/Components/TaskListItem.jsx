import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuCheck, LuPencil, LuPaperclip, LuTrash2, LuUndo2 } from 'react-icons/lu';

/**
 * Одна задача в списке.
 *
 * Используется во врезке карточки сущности и в виджете дашборда — поведение
 * у них одинаковое, поэтому оно живёт здесь, а не дублируется в двух местах.
 *
 * @param {object} task — payload из CrmTaskService::payload()
 * @param {boolean} showEntity — показывать ли, к чему задача привязана
 */
export default function TaskListItem({ task, showEntity = false, onEdit, onToggleDone, onDelete, busy = false }) {
    const done = task.status === 'done';

    return (
        <Box
            borderWidth="1px"
            borderColor={task.is_overdue ? 'red.400' : 'border'}
            borderRadius="md"
            p={3}
            opacity={done || task.status === 'canceled' ? 0.65 : 1}
        >
            <VStack align="stretch" gap={2}>
                <HStack justify="space-between" align="start" gap={2} flexWrap="wrap">
                    <VStack align="start" gap={1} flex="1" minW="200px">
                        <Text
                            fontSize="sm"
                            fontWeight="600"
                            textDecoration={done ? 'line-through' : undefined}
                        >
                            {task.title}
                        </Text>

                        <HStack gap={2} flexWrap="wrap">
                            <Badge colorPalette={task.status_color} variant="subtle" size="sm">
                                {task.status_label}
                            </Badge>
                            {task.priority !== 'normal' && (
                                <Badge colorPalette={task.priority_color} variant="subtle" size="sm">
                                    {task.priority_label}
                                </Badge>
                            )}
                            {task.is_overdue && (
                                <Badge colorPalette="red" variant="solid" size="sm">Просрочена</Badge>
                            )}
                            {task.due_at_label && (
                                <Text fontSize="xs" color={task.is_overdue ? 'red.500' : 'fg.muted'}>
                                    Срок: {task.due_at_label}
                                </Text>
                            )}
                            <Text fontSize="xs" color="fg.muted">Исполнитель: {task.assignee?.name}</Text>
                            {showEntity && task.entity && (
                                <Badge colorPalette="gray" variant="subtle" size="sm">
                                    {task.entity.url
                                        ? <a href={task.entity.url}>{task.entity.title}</a>
                                        : task.entity.title}
                                </Badge>
                            )}
                            {task.attachments_count > 0 && (
                                <HStack gap={1} color="fg.muted">
                                    <LuPaperclip size={12} />
                                    <Text fontSize="xs">{task.attachments_count}</Text>
                                </HStack>
                            )}
                        </HStack>
                    </VStack>

                    <HStack gap={1}>
                        {task.can?.update && (
                            <Button
                                size="xs"
                                variant="ghost"
                                colorPalette={done ? undefined : 'green'}
                                disabled={busy}
                                onClick={() => onToggleDone?.(task)}
                                title={done ? 'Вернуть в работу' : 'Отметить выполненной'}
                            >
                                {done ? <LuUndo2 /> : <LuCheck />}
                            </Button>
                        )}
                        <Button
                            size="xs"
                            variant="ghost"
                            disabled={busy}
                            onClick={() => onEdit?.(task)}
                            title="Открыть задачу"
                        >
                            <LuPencil />
                        </Button>
                        {task.can?.delete && (
                            <Button
                                size="xs"
                                variant="ghost"
                                colorPalette="red"
                                disabled={busy}
                                onClick={() => onDelete?.(task)}
                                title="Удалить"
                            >
                                <LuTrash2 />
                            </Button>
                        )}
                    </HStack>
                </HStack>

                {task.description && (
                    <Text fontSize="sm" color="fg.muted" whiteSpace="pre-wrap">{task.description}</Text>
                )}
            </VStack>
        </Box>
    );
}
