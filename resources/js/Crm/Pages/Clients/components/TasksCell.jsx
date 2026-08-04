import { Box, Badge, HStack, Text, VStack } from '@chakra-ui/react';
import { LuCirclePlus } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Как выглядит срок ближайшей задачи.
 *
 * Состояние (`due_state`) считает бэкенд — разбирать даты в JSX означало бы
 * держать часовой пояс сервера в двух местах.
 */
const DUE_STYLE = {
    overdue: { palette: 'red', variant: 'solid' },
    today: { palette: 'orange', variant: 'solid' },
    tomorrow: { palette: 'orange', variant: 'subtle' },
    week: { palette: 'blue', variant: 'subtle' },
    later: { palette: 'gray', variant: 'subtle' },
    none: { palette: 'gray', variant: 'outline' },
};

function dueLabel(next) {
    if (next.due_state === 'overdue') {
        return next.overdue_days > 0
            ? `Просрочена, ${next.overdue_days} дн.`
            : 'Просрочена';
    }
    if (next.due_state === 'today') return `Сегодня ${next.due_at_label?.slice(-5) || ''}`.trim();
    if (next.due_state === 'tomorrow') return `Завтра ${next.due_at_label?.slice(-5) || ''}`.trim();
    if (next.due_state === 'none') return 'Без срока';

    return next.due_at_label || 'Без срока';
}

/**
 * Ячейка «Задачи»: сколько активных и когда ближайшая.
 *
 * Кликабельна целиком — ставить задачу менеджер должен оттуда, где увидел,
 * что её нет, а не после двух переходов в карточку.
 *
 * @param {{active_count: number, next: object|null}|null} tasks
 * @param {Function} onCreate — открыть диалог новой задачи по этому клиенту
 */
export default function TasksCell({ tasks, onCreate }) {
    if (!tasks) return null;

    const next = tasks.next;
    const style = DUE_STYLE[next?.due_state] || DUE_STYLE.none;

    return (
        <Tooltip
            content={next
                ? `${next.title}${next.due_at_full ? ` — до ${next.due_at_full}` : ''}${next.assignee_name ? `, ${next.assignee_name}` : ''}`
                : 'По клиенту нет следующего шага — нажмите, чтобы поставить задачу'}
            openDelay={300}
        >
            <Box
                as="button"
                type="button"
                onClick={onCreate}
                textAlign="left"
                borderRadius="md"
                px={1}
                py={0.5}
                _hover={{ bg: 'bg.muted' }}
                aria-label={next ? 'Открыть постановку задачи' : 'Поставить задачу'}
            >
                <VStack align="start" gap={0.5}>
                    {next ? (
                        <>
                            <Badge colorPalette={style.palette} variant={style.variant} size="sm">
                                {dueLabel(next)}
                            </Badge>
                            {tasks.active_count > 1 && (
                                <Text fontSize="10px" color="fg.muted">
                                    всего активных: {tasks.active_count}
                                </Text>
                            )}
                        </>
                    ) : (
                        <HStack gap={1}>
                            <Badge colorPalette="orange" variant="outline" size="sm">нет задач</Badge>
                            <LuCirclePlus size={13} color="var(--chakra-colors-fg-muted)" />
                        </HStack>
                    )}
                </VStack>
            </Box>
        </Tooltip>
    );
}
