import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import RowActions from '@/shared/Panel/RowActions';
import { LuCheck, LuPaperclip, LuUndo2 } from 'react-icons/lu';

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

                    <RowActions
                        size="xs"
                        view={onEdit ? { label: 'Открыть задачу', disabled: busy, onClick: () => onEdit(task) } : null}
                        extra={[
                            task.can?.update && onToggleDone && {
                                key: 'toggle',
                                icon: done ? LuUndo2 : LuCheck,
                                label: done ? 'Вернуть в работу' : 'Отметить выполненной',
                                colorPalette: done ? undefined : 'green',
                                disabled: busy,
                                onClick: () => onToggleDone(task),
                            },
                        ].filter(Boolean)}
                        delete={onDelete ? { allowed: !!task.can?.delete, disabled: busy, onClick: () => onDelete(task) } : null}
                    />
                </HStack>

                {task.description && (
                    <Text fontSize="sm" color="fg.muted" whiteSpace="pre-wrap">{task.description}</Text>
                )}
            </VStack>
        </Box>
    );
}
