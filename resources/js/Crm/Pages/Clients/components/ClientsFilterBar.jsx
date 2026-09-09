import { useEffect, useState } from 'react';
import { Box, HStack, Input, Text } from '@chakra-ui/react';
import { LuSlidersHorizontal } from 'react-icons/lu';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';

/**
 * Граница суммы отбора.
 *
 * Значение уходит на сервер по потере фокуса и по Enter, а не на каждое нажатие:
 * иначе набор «100000» превратился бы в шесть запросов, каждый со своей выдачей.
 */
function AmountInput({ value, onCommit, placeholder }) {
    const [draft, setDraft] = useState(value ?? '');

    // Значение могло измениться извне — применили сохранённый отбор или сбросили.
    useEffect(() => setDraft(value ?? ''), [value]);

    const commit = () => {
        const next = draft === '' ? undefined : draft;

        if (String(next ?? '') !== String(value ?? '')) {
            onCommit(next);
        }
    };

    return (
        <Input
            size="xs"
            type="number"
            min={0}
            maxW="80px"
            value={draft}
            placeholder={placeholder}
            onChange={(event) => setDraft(event.target.value)}
            onBlur={commit}
            onKeyDown={(event) => event.key === 'Enter' && commit()}
        />
    );
}

/**
 * Один селект отбора.
 *
 * Пустое значение всегда означает «неважно» и уходит из запроса как undefined —
 * иначе в адресной строке копились бы пустые параметры и портили сохранённый отбор.
 * Выбранный селект подсвечивается рамкой: в компактном ряду иначе не видно,
 * что отбор вообще включён.
 */
function FilterSelect({ value, onChange, placeholder, options, minW = '150px' }) {
    const chosen = value !== undefined && value !== null && value !== '';

    return (
        <Box minW={minW}>
            <NativeSelectRoot size="xs">
                <NativeSelectField
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value || undefined)}
                    fontWeight={chosen ? '600' : '400'}
                    borderColor={chosen ? 'fg' : undefined}
                    color={chosen ? 'fg' : 'fg.muted'}
                >
                    <option value="">{placeholder}</option>
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </NativeSelectField>
            </NativeSelectRoot>
        </Box>
    );
}

/**
 * Уточняющие отборы списка партнёров — компактный ряд под воронкой.
 *
 * Стадии здесь нет: её выбирают чипами воронки. Всё остальное — менеджер,
 * задачи, план, покупки, заказы, страховой запас — комплементарно воронке:
 * сужает базу, по которой считаются её чипы, а не спорит с ней.
 *
 * @param {object} filters
 * @param {Function} onChange — применить один изменившийся параметр
 * @param {Array} managers
 */
export default function ClientsFilterBar({
    filters,
    onChange,
    managers = [],
    canSeeAll = false,
    canSeeTasks = false,
    canSeePlans = false,
    uncoveredCount = null,
    children = null,
}) {
    return (
        <HStack gap={2} align="center" wrap="wrap">
            <HStack gap={1} color="fg.muted" pr={1}>
                <LuSlidersHorizontal size={13} />
                <Text fontSize="xs" whiteSpace="nowrap">Уточнить</Text>
            </HStack>

            {children}

            {canSeeAll && (
                <FilterSelect
                    value={filters.manager_id}
                    onChange={(value) => onChange({ manager_id: value })}
                    placeholder="Все менеджеры"
                    minW="170px"
                    options={managers.map((manager) => ({
                        value: String(manager.id),
                        label: manager.name,
                    }))}
                />
            )}

            {canSeeTasks && (
                <FilterSelect
                    value={filters.task_state}
                    onChange={(value) => onChange({ task_state: value })}
                    placeholder="Задачи: неважно"
                    options={[
                        { value: 'overdue', label: 'Есть просроченные' },
                        { value: 'today', label: 'Есть на сегодня' },
                        { value: 'week', label: 'Есть на неделю' },
                        { value: 'any', label: 'Есть активные' },
                        {
                            value: 'none',
                            label: uncoveredCount !== null ? `Без задач (${uncoveredCount})` : 'Без задач',
                        },
                    ]}
                />
            )}

            {canSeePlans && (
                <FilterSelect
                    value={filters.plan_state}
                    onChange={(value) => onChange({ plan_state: value })}
                    placeholder="План: неважно"
                    options={[
                        { value: 'behind', label: 'Отстают от плана' },
                        { value: 'ahead', label: 'Выполнили план' },
                        { value: 'with_plan', label: 'План задан' },
                        { value: 'without_plan', label: 'Плана нет' },
                    ]}
                />
            )}

            <FilterSelect
                value={filters.inactive_days}
                onChange={(value) => onChange({ inactive_days: value })}
                placeholder="Покупки: неважно"
                options={[
                    { value: '30', label: 'Не покупает 30 дней' },
                    { value: '60', label: 'Не покупает 60 дней' },
                    { value: '90', label: 'Не покупает 90 дней' },
                ]}
            />

            {/* Отдельно от «не покупает»: там отгрузки (факт), здесь заказы
                (намерение). Клиент мог заказать вчера и ещё не получить товар. */}
            <FilterSelect
                value={filters.no_order_days}
                onChange={(value) => onChange({ no_order_days: value })}
                placeholder="Заказы: неважно"
                options={[
                    { value: '30', label: 'Не заказывал 30 дней' },
                    { value: '60', label: 'Не заказывал 60 дней' },
                    { value: '90', label: 'Не заказывал 90 дней' },
                ]}
            />

            {/* Страховой запас (buf-02): включённых ~50 — менеджеру нужен их
                список одним кликом. */}
            <FilterSelect
                value={filters.stock_buffer}
                onChange={(value) => onChange({ stock_buffer: value })}
                placeholder="Страховой запас: неважно"
                minW="190px"
                options={[
                    { value: 'enabled', label: 'Страховой запас включён' },
                    { value: 'disabled', label: 'Страховой запас выключен' },
                ]}
            />

            <HStack gap={1} align="center">
                <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">Заказ, ₽</Text>
                <AmountInput
                    value={filters.order_amount_from}
                    onCommit={(value) => onChange({ order_amount_from: value })}
                    placeholder="от"
                />
                <AmountInput
                    value={filters.order_amount_to}
                    onCommit={(value) => onChange({ order_amount_to: value })}
                    placeholder="до"
                />
            </HStack>
        </HStack>
    );
}
