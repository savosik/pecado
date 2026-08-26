import { useState } from 'react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import TaskChecklist from '@/Crm/Components/TaskChecklist';
import RowActions from '@/shared/Panel/RowActions';
import {
    LuCheck,
    LuChevronDown,
    LuChevronUp,
    LuClock,
    LuEye,
    LuListChecks,
    LuPin,
    LuPinOff,
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
    // Обновлённые счётчики чек-листа после отметки в развёрнутой строке.
    onChecklistChanged,
}) {
    const closed = task.status === 'done' || task.status === 'canceled';
    // Чек-лист разворачивается прямо в строке: отметить галочку не должно
    // стоить открытия карточки.
    const [checklistOpen, setChecklistOpen] = useState(false);
    const [counts, setCounts] = useState(null);

    const checklistTotal = counts?.checklist_total ?? task.checklist_total ?? 0;
    const checklistDone = counts?.checklist_done ?? task.checklist_done ?? 0;

    return (
        <VStack
            gap={0}
            align="stretch"
            borderWidth="1px"
            borderRadius="md"
            opacity={closed ? 0.75 : 1}
        >
        <HStack
            gap={3}
            px={3}
            py={2}
            align="center"
            _hover={{ bg: 'bg.muted' }}
        >
            <Box flex="1" minW={0}>
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

                    {checklistTotal > 0 && (
                        // Кликабельный прогресс: разворачивает чек-лист под строкой.
                        <HStack
                            gap={1}
                            color={checklistDone === checklistTotal ? 'green.fg' : 'fg.muted'}
                            cursor="pointer"
                            onClick={(e) => {
                                e.stopPropagation();
                                setChecklistOpen((prev) => !prev);
                            }}
                            title={checklistOpen ? 'Свернуть чек-лист' : 'Развернуть чек-лист'}
                            _hover={{ color: 'fg' }}
                        >
                            <LuListChecks size={13} />
                            <Text fontSize="xs">{checklistDone}/{checklistTotal}</Text>
                            <Box
                                w="46px"
                                h="4px"
                                borderRadius="full"
                                bg="bg.muted"
                                borderWidth="1px"
                                overflow="hidden"
                            >
                                <Box
                                    h="100%"
                                    w={`${checklistTotal ? Math.round((checklistDone / checklistTotal) * 100) : 0}%`}
                                    bg={checklistDone === checklistTotal ? 'green.solid' : 'blue.solid'}
                                    transition="width 0.2s"
                                />
                            </Box>
                            {checklistOpen ? <LuChevronUp size={12} /> : <LuChevronDown size={12} />}
                        </HStack>
                    )}

                    {task.is_watched && (
                        <Box color="purple.fg" title="У вас на личном контроле"><LuEye size={13} /></Box>
                    )}

                    {(task.tags || []).map((tag) => (
                        <Badge key={tag} variant="outline" colorPalette="purple" size="sm">{tag}</Badge>
                    ))}
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

            <Box flexShrink={0}>
                <RowActions
                    size="xs"
                    view={onOpen ? { label: 'Открыть задачу', onClick: () => onOpen(task) } : null}
                    extra={[
                        onToggleDone && task.can?.update && {
                            key: 'toggle',
                            icon: closed ? LuUndo2 : LuCheck,
                            label: closed ? 'Вернуть в работу' : 'Завершить',
                            disabled: busy,
                            onClick: () => onToggleDone(task),
                        },
                        onPin && !closed && {
                            key: 'pin',
                            icon: task.is_pinned ? LuPinOff : LuPin,
                            label: task.is_pinned ? 'Открепить' : 'Закрепить сверху',
                            colorPalette: task.is_pinned ? 'blue' : undefined,
                            disabled: busy,
                            onClick: () => onPin(task),
                        },
                    ].filter(Boolean)}
                    delete={onDelete ? { allowed: !!task.can?.delete, disabled: busy, onClick: () => onDelete(task) } : null}
                />
            </Box>
        </HStack>

        {checklistOpen && (
            <Box px={3} pb={2} pt={1} borderTopWidth="1px" bg="bg.subtle" borderBottomRadius="md">
                <TaskChecklist
                    taskId={task.id}
                    items={null}
                    canEdit={!!task.can?.update && !closed}
                    onChanged={(data) => {
                        setCounts({
                            checklist_total: data.checklist_total,
                            checklist_done: data.checklist_done,
                        });
                        onChecklistChanged?.(task, data);
                    }}
                />
            </Box>
        )}
        </VStack>
    );
}
