import { Badge, HStack, Wrap, WrapItem } from '@chakra-ui/react';
import { LuFilterX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';

/**
 * Быстрые отборы — не записи в БД, а готовые наборы параметров.
 *
 * Отличаются от сохранённых отборов принципиально: эти одинаковы у всех и
 * покрывают ежедневные вопросы («что горит», «кем не занимались»), а личные
 * отборы менеджер собирает сам.
 *
 * @param {object} filters — текущее состояние фильтров
 * @param {Function} onApply — применить набор параметров
 * @param {Function} onReset
 * @param {boolean} canSeeTasks
 * @param {boolean} canSeePlans
 * @param {number|null} uncoveredCount
 */
export default function QuickFilters({
    filters,
    onApply,
    onReset,
    canSeeTasks = false,
    canSeePlans = false,
    uncoveredCount = null,
}) {
    const chips = [
        ...(canSeeTasks ? [
            {
                key: 'overdue',
                label: 'Просроченные задачи',
                palette: 'red',
                params: { task_state: 'overdue', sort_by: 'next_task_due', sort_order: 'asc' },
                active: filters.task_state === 'overdue',
            },
            {
                key: 'today',
                label: 'На сегодня',
                palette: 'orange',
                params: { task_state: 'today', sort_by: 'next_task_due', sort_order: 'asc' },
                active: filters.task_state === 'today',
            },
            {
                key: 'none',
                label: uncoveredCount !== null ? `Без задач (${uncoveredCount})` : 'Без задач',
                palette: 'purple',
                params: { task_state: 'none' },
                active: filters.task_state === 'none',
            },
        ] : []),
        {
            key: 'inactive',
            label: 'Не покупает 60 дней',
            palette: 'gray',
            params: { inactive_days: 60 },
            active: Number(filters.inactive_days) === 60,
        },
        ...(canSeePlans ? [{
            key: 'behind',
            label: 'Отстают от плана',
            palette: 'red',
            params: { plan_state: 'behind', sort_by: 'plan_percent', sort_order: 'asc' },
            active: filters.plan_state === 'behind',
        }] : []),
    ];

    const hasAny = Boolean(
        filters.search || filters.lifecycle || filters.task_state || filters.coverage
        || filters.plan_state || filters.inactive_days || filters.manager_id,
    );

    return (
        <Wrap gap={2} align="center">
            {chips.map((chip) => (
                <WrapItem key={chip.key}>
                    <Badge
                        as="button"
                        type="button"
                        colorPalette={chip.palette}
                        variant={chip.active ? 'solid' : 'outline'}
                        px={3}
                        py={1}
                        borderRadius="full"
                        cursor="pointer"
                        // Повторный клик снимает отбор: чип работает как переключатель,
                        // иначе «показать просроченные» невозможно было бы выключить.
                        onClick={() => onApply(chip.active
                            ? Object.fromEntries(Object.keys(chip.params).map((k) => [k, undefined]))
                            : chip.params)}
                    >
                        {chip.label}
                    </Badge>
                </WrapItem>
            ))}

            {hasAny && (
                <WrapItem>
                    <Button size="xs" variant="ghost" onClick={onReset}>
                        <HStack gap={1}><LuFilterX size={13} /> <span>Сбросить</span></HStack>
                    </Button>
                </WrapItem>
            )}
        </Wrap>
    );
}
