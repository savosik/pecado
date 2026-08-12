import { Badge, Box, HStack, IconButton, Text, VStack } from '@chakra-ui/react';
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
 * Подсказка по существующей задаче: что сделать, к какому сроку и на ком.
 *
 * Описание в подсказке обрезаем: у поручений оно бывает на абзац, а всплывающее
 * окно на пол-экрана перекрывает саму таблицу.
 */
function hint(next) {
    const parts = [next.title];

    if (next.description) {
        parts.push(next.description.length > 160
            ? `${next.description.slice(0, 160)}…`
            : next.description);
    }

    if (next.due_at_full) parts.push(`Срок: ${next.due_at_full}`);
    if (next.assignee_name) parts.push(`Исполнитель: ${next.assignee_name}`);

    return parts.join('\n');
}

/**
 * Ячейка «Задачи»: сколько активных и когда ближайшая.
 *
 * Два разных действия на одной ячейке, и их нельзя путать: клик по сроку
 * открывает существующую задачу (её чаще нужно посмотреть или закрыть),
 * плюс — ставит новую. Раньше вся ячейка вела на создание, и открыть задачу
 * из списка было нельзя вовсе.
 *
 * Когда задач нет, различать нечего — кликабельна вся ячейка.
 *
 * @param {{active_count: number, next: object|null}|null} tasks
 * @param {Function} onCreate — открыть диалог новой задачи по этому партнёру
 * @param {Function} onOpen — открыть существующую задачу по её id
 */
export default function TasksCell({ tasks, onCreate, onOpen }) {
    if (! tasks) return null;

    const next = tasks.next;
    const style = DUE_STYLE[next?.due_state] || DUE_STYLE.none;

    if (! next) {
        return (
            <Tooltip
                content="По партнёру нет следующего шага — нажмите, чтобы поставить задачу"
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
                    aria-label="Поставить задачу"
                >
                    <HStack gap={1}>
                        <Badge colorPalette="orange" variant="outline" size="sm">нет задач</Badge>
                        <LuCirclePlus size={13} color="var(--chakra-colors-fg-muted)" />
                    </HStack>
                </Box>
            </Tooltip>
        );
    }

    return (
        <VStack align="start" gap={0.5}>
            <HStack gap={1}>
                <Tooltip content={hint(next)} openDelay={300} contentProps={{ whiteSpace: 'pre-line' }}>
                    <Box
                        as="button"
                        type="button"
                        onClick={() => onOpen?.(next.id)}
                        borderRadius="md"
                        px={1}
                        py={0.5}
                        _hover={{ bg: 'bg.muted' }}
                        aria-label={`Открыть задачу: ${next.title}`}
                    >
                        <Badge colorPalette={style.palette} variant={style.variant} size="sm">
                            {dueLabel(next)}
                        </Badge>
                    </Box>
                </Tooltip>

                <Tooltip content="Поставить ещё одну задачу" openDelay={300}>
                    <IconButton
                        size="2xs"
                        variant="ghost"
                        aria-label="Поставить задачу"
                        onClick={onCreate}
                    >
                        <LuCirclePlus />
                    </IconButton>
                </Tooltip>
            </HStack>

            {tasks.active_count > 1 && (
                <Text fontSize="10px" color="fg.muted">
                    всего активных: {tasks.active_count}
                </Text>
            )}
        </VStack>
    );
}
