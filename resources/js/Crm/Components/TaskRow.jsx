import { Badge, Box, HStack, IconButton, Text } from '@chakra-ui/react';
import {
    LuCheck,
    LuClock,
    LuEye,
    LuListChecks,
    LuPencil,
    LuPin,
    LuPinOff,
    LuTrash2,
    LuUndo2,
    LuUsers,
} from 'react-icons/lu';

/**
 * Строка задачи — общая для раздела и блока на дашборде.
 *
 * Показывает всё, что появилось в v2: чек-лист, соисполнителей, контроль,
 * трудоёмкость, исход и переносы. Действия отдаются колбэками — сама строка
 * не знает, из какого списка её нарисовали.
 */
export default function TaskRow({
    task,
    busy = false,
    showAssignee = true,
    onToggleDone,
    onOpen,
    onPin,
    onDelete,
}) {
    const closed = task.status === 'done' || task.status === 'canceled';

    return (
        <HStack
            gap={3}
            px={3}
            py={2}
            borderWidth="1px"
            borderRadius="md"
            align="center"
            _hover={{ bg: 'bg.muted' }}
            opacity={closed ? 0.75 : 1}
        >
            {onToggleDone && task.can?.update && (
                <IconButton
                    size="xs"
                    variant={closed ? 'ghost' : 'outline'}
                    colorPalette={closed ? undefined : 'green'}
                    aria-label={closed ? 'Вернуть в работу' : 'Завершить'}
                    title={closed ? 'Вернуть в работу' : 'Завершить'}
                    disabled={busy}
                    onClick={() => onToggleDone(task)}
                >
                    {closed ? <LuUndo2 /> : <LuCheck />}
                </IconButton>
            )}

            <Box flex="1" minW={0} cursor="pointer" onClick={() => onOpen?.(task)}>
                <HStack gap={2} flexWrap="wrap">
                    <Text
                        fontSize="sm"
                        fontWeight="600"
                        textDecoration={task.status === 'done' ? 'line-through' : undefined}
                        lineClamp={1}
                    >
                        {task.title}
                    </Text>

                    {task.priority !== 'normal' && (
                        <Badge colorPalette={task.priority_color} variant="subtle" size="sm">
                            {task.priority_label}
                        </Badge>
                    )}

                    {task.outcome && (
                        <Badge colorPalette={task.outcome_color} variant="subtle" size="sm">
                            {task.outcome_label}
                        </Badge>
                    )}

                    {task.postponed_count > 0 && (
                        <Badge colorPalette="gray" variant="subtle" size="sm" title="Сколько раз переносился срок">
                            переносилась ×{task.postponed_count}
                        </Badge>
                    )}

                    {task.checklist_total > 0 && (
                        <HStack gap={1} color={task.checklist_done === task.checklist_total ? 'green.fg' : 'fg.muted'}>
                            <LuListChecks size={13} />
                            <Text fontSize="xs">{task.checklist_done}/{task.checklist_total}</Text>
                        </HStack>
                    )}

                    {task.is_watched && (
                        <Box color="purple.fg" title="У вас на личном контроле"><LuEye size={13} /></Box>
                    )}
                </HStack>

                <HStack gap={3} mt={0.5} flexWrap="wrap">
                    {task.entity && (
                        <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                            {task.entity.label}: {task.entity.title}
                        </Text>
                    )}

                    {showAssignee && (
                        <HStack gap={1} color="fg.muted">
                            {task.co_assignees?.length > 0 && <LuUsers size={12} />}
                            <Text fontSize="xs">
                                {task.assignee?.name}
                                {task.co_assignees?.length > 0 && ` +${task.co_assignees.length}`}
                                {task.author?.id !== task.assignee?.id && ` · от ${task.author?.name}`}
                            </Text>
                        </HStack>
                    )}

                    {task.estimate_label && (
                        <HStack gap={1} color="fg.muted">
                            <LuClock size={12} />
                            <Text fontSize="xs">{task.estimate_label}</Text>
                        </HStack>
                    )}

                    {task.due_at_label
                        ? (
                            <Text fontSize="xs" color={task.is_overdue ? 'red.fg' : 'fg.muted'} fontWeight={task.is_overdue ? '600' : undefined}>
                                {task.is_overdue ? 'Просрочена · ' : ''}{task.due_at_label}
                            </Text>
                        )
                        : <Text fontSize="xs" color="fg.muted">без срока</Text>}
                </HStack>
            </Box>

            <HStack gap={1} flexShrink={0}>
                {onPin && !closed && (
                    <IconButton
                        size="xs"
                        variant="ghost"
                        colorPalette={task.is_pinned ? 'blue' : undefined}
                        aria-label={task.is_pinned ? 'Открепить' : 'Закрепить сверху'}
                        title={task.is_pinned ? 'Открепить' : 'Закрепить сверху'}
                        disabled={busy}
                        onClick={() => onPin(task)}
                    >
                        {task.is_pinned ? <LuPinOff /> : <LuPin />}
                    </IconButton>
                )}

                {onOpen && (
                    <IconButton size="xs" variant="ghost" aria-label="Открыть задачу" title="Открыть задачу" onClick={() => onOpen(task)}>
                        <LuPencil />
                    </IconButton>
                )}

                {onDelete && task.can?.delete && (
                    <IconButton
                        size="xs"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить"
                        title="Удалить"
                        disabled={busy}
                        onClick={() => onDelete(task)}
                    >
                        <LuTrash2 />
                    </IconButton>
                )}
            </HStack>
        </HStack>
    );
}
