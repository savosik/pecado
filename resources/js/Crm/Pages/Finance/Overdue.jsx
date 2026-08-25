import { Fragment, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuChevronRight, LuListPlus } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import AmountFilterInput from '@/Crm/Components/AmountFilterInput';
import MetricHint from '@/Crm/Components/MetricHint';
import TaskDialog from '@/Crm/Components/TaskDialog';
import { usePermission } from '@/shared/Panel/usePermission';
import FinanceFilterBar from './components/FinanceFilterBar';
import FinanceRowsTable from './components/FinanceRowsTable';
import { formatRub } from './components/format';

/** Пороги «мелочи»: копеечные хвосты закрываются взаимозачётом, а не звонком. */
const AMOUNT_PRESETS = [
    { value: 1, label: 'от 1 ₽' },
    { value: 1000, label: 'от 1 000 ₽' },
    { value: 10000, label: 'от 10 000 ₽' },
];

/**
 * Просроченные платежи — строки графика, срок которых уже прошёл.
 *
 * Раздел отвечает на три разных вопроса, и потому у него три поверхности:
 * сколько всего просрочено (итоги), как давно (корзины старения) и где долг
 * копится (разрез по партнёрам, менеджерам, нашим юрлицам, контрагентам).
 *
 * Период фильтров здесь не нужен вовсе: просрочка — это состояние на сегодня,
 * а не оборот за окно. Раньше панель показывала «плановая дата с…», которая
 * на список не влияла, — отбор, который ничего не делает, хуже отсутствующего.
 */
export default function FinanceOverdue({
    rows,
    totals = {},
    debtTotal = 0,
    aging = null,
    group = '',
    groups = [],
    groupRows = null,
    sort = { column: 'due_date', direction: 'asc' },
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    const { can } = usePermission();
    const [taskFor, setTaskFor] = useState(null);
    const [expanded, setExpanded] = useState({});

    const toggle = (id) => setExpanded((previous) => ({ ...previous, [id]: ! previous[id] }));

    /** Отбор меняется поверх текущего адреса: разрез и прочие фильтры остаются. */
    const patchQuery = (patch) => {
        const query = new URLSearchParams(window.location.search);

        Object.entries(patch).forEach(([key, value]) => {
            query.delete(key);
            query.delete(`${key}[]`);

            if (value === undefined || value === null || value === '') return;
            if (Array.isArray(value)) value.forEach((item) => query.append(`${key}[]`, item));
            else query.set(key, value);
        });

        // Страница пагинации после смены отбора всегда первая: на третьей
        // странице суженного списка чаще всего уже пусто.
        query.delete('page');

        router.get(`/crm/finance/overdue?${query.toString()}`, {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const activeBuckets = filters.overdue_buckets || [];

    // Доля просрочки в общем долге: главный индикатор раздела. Долг приходит
    // отрицательным сальдо, поэтому сравниваем модули.
    const debt = Math.abs(debtTotal || 0);
    const debtShare = debt > 0 ? Math.round(((totals.amount || 0) / debt) * 100) : 0;

    const toggleBucket = (key) => patchQuery({
        overdue_buckets: activeBuckets.includes(key)
            ? activeBuckets.filter((item) => item !== key)
            : [...activeBuckets, key],
    });

    const changeSort = (column) => patchQuery({
        sort: column,
        direction: sort.column === column && sort.direction === 'asc' ? 'desc' : 'asc',
    });

    const bucketLabel = (key) => aging?.buckets.find((bucket) => bucket.key === key)?.label ?? key;

    const chips = [
        ...activeBuckets.map((key) => ({
            key: `bucket:${key}`,
            label: 'Задержка',
            value: bucketLabel(key),
            onRemove: () => toggleBucket(key),
        })),
        ...(filters.min_amount ? [{
            key: 'min_amount',
            label: 'Остаток',
            value: `от ${formatRub(filters.min_amount)}`,
            onRemove: () => patchQuery({ min_amount: undefined }),
        }] : []),
    ];

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'Просрочка' }]}>
            <Head title="Просроченные платежи — CRM" />

            <PageHeader
                title="Просроченные платежи"
                description="Деньги, которые должны были прийти, но не пришли"
            />

            <FinanceFilterBar
                routeName="crm.finance.overdue"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
                hidePeriod
                extraChips={chips}
                passthrough={['overdue_buckets', 'min_amount', 'group', 'sort', 'direction']}
                extraControls={(
                    <HStack gap={2} wrap="wrap" align="center">
                        <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">Остаток от</Text>

                        <Button
                            size="xs"
                            variant={filters.min_amount ? 'outline' : 'solid'}
                            colorPalette={filters.min_amount ? 'gray' : 'pecado'}
                            onClick={() => patchQuery({ min_amount: undefined })}
                        >
                            Любой
                        </Button>

                        {AMOUNT_PRESETS.map((preset) => (
                            <Button
                                key={preset.value}
                                size="xs"
                                variant={Number(filters.min_amount) === preset.value ? 'solid' : 'outline'}
                                colorPalette={Number(filters.min_amount) === preset.value ? 'pecado' : 'gray'}
                                onClick={() => patchQuery({ min_amount: preset.value })}
                            >
                                {preset.label}
                            </Button>
                        ))}

                        <AmountFilterInput
                            width="120px"
                            aria-label="Остаток от, ₽"
                            placeholder="своя сумма"
                            value={filters.min_amount ?? ''}
                            onCommit={(value) => patchQuery({ min_amount: value })}
                        />

                        <MetricHint text="Отсекает копеечные хвосты: недоплаты в рубль-другой закрываются взаимозачётом при следующей отгрузке, а в списке к взысканию только мешают." />
                    </HStack>
                )}
            />

            {/* Итоги одной строкой: четыре карточки в полэкрана отодвигали
                таблицу за сгиб, а читаются эти числа мельком. */}
            <Box borderWidth="1px" borderRadius="lg" px={4} py={3} mb={3} bg="bg.panel">
                <Flex gap={{ base: 3, md: 6 }} wrap="wrap" align="center">
                    <Metric
                        label="Просрочено"
                        value={formatRub(totals.amount)}
                        tone="red"
                        hint="Сумма непогашенных остатков по строкам графика, срок которых прошёл. Учитывает все фильтры сверху, включая корзину задержки и порог остатка. Планы по заказам сюда не входят: долг создаёт отгрузка, а не заказ."
                    />

                    <Metric
                        label="Общий долг"
                        value={formatRub(Math.abs(debtTotal))}
                        hint="Сальдо взаиморасчётов по тем же партнёрам — весь долг, а не только просроченная его часть. Считается по регистру 1С, как в разделе «Балансы»."
                    />

                    <Metric
                        label="Доля просрочки"
                        value={`${debtShare}%`}
                        tone={debtShare >= 50 ? 'red' : undefined}
                        hint="Какая часть общего долга уже просрочена. Растущая доля означает, что деньги не приходят в срок, даже если сам долг не меняется. Числитель — просрочка по текущему отбору, знаменатель — весь долг тех же партнёров: при выбранной корзине задержки доля показывает вклад именно этой корзины."
                        after={<ShareBar value={debtShare} tone={debtShare >= 50 ? 'red' : 'orange'} width="120px" />}
                    />

                    <Metric
                        label="Строк графика"
                        value={String(totals.lines || 0)}
                        hint="Сколько строк графика просрочено. У одной реализации строк бывает несколько — по этапам оплаты, поэтому строк всегда не меньше, чем документов."
                    />

                    <Metric
                        label="Документов"
                        value={String(totals.documents || 0)}
                        hint="Сколько разных реализаций стоит за этими строками."
                    />

                    <Metric
                        label="Партнёров"
                        value={String(totals.clients || 0)}
                        hint="Сколько партнёров имеют хотя бы одну просроченную строку. Это те, кому предстоит звонить."
                    />
                </Flex>
            </Box>

            {aging && (
                <Box borderWidth="1px" borderRadius="lg" p={4} mb={4}>
                    <HStack gap={2} mb={3}>
                        <Text fontWeight="600">По срокам задержки</Text>
                        <MetricHint text="Корзина считается от плановой даты строки до сегодня. Плитки показывают всю просрочку по текущему отбору партнёров и юрлиц — выбор корзины на них не влияет, иначе переключиться на соседнюю было бы нечем. Клик по плитке фильтрует список; можно выбрать несколько." />
                        {activeBuckets.length > 0 && (
                            <Button size="xs" variant="ghost" onClick={() => patchQuery({ overdue_buckets: undefined })}>
                                Показать все
                            </Button>
                        )}
                    </HStack>

                    <SimpleGrid columns={{ base: 2, md: 5 }} gap={3}>
                        {aging.buckets.map((bucket) => {
                            const active = activeBuckets.includes(bucket.key);

                            return (
                                <Box
                                    key={bucket.key}
                                    as="button"
                                    type="button"
                                    textAlign="left"
                                    borderWidth="1px"
                                    borderColor={active ? 'pecado.solid' : 'border.muted'}
                                    bg={active ? 'pecado.subtle' : undefined}
                                    borderRadius="md"
                                    p={3}
                                    onClick={() => toggleBucket(bucket.key)}
                                    _hover={{ borderColor: 'pecado.solid' }}
                                >
                                    <Text fontSize="xs" color="fg.muted">{bucket.label}</Text>
                                    <Text fontSize="md" fontWeight="600">{formatRub(bucket.amount)}</Text>
                                    <Text fontSize="10px" color="fg.muted">{bucket.count} стр.</Text>
                                </Box>
                            );
                        })}
                    </SimpleGrid>
                </Box>
            )}

            {/* Разрез отдельной строкой, как в балансах: он меняет не состав
                строк, а форму отчёта — это выбор представления, не фильтр. */}
            <HStack gap={2} wrap="wrap" mb={3} px={1}>
                <Text fontSize="xs" color="fg.muted">Показать:</Text>

                <Button
                    size="xs"
                    variant={group === '' ? 'solid' : 'outline'}
                    colorPalette={group === '' ? 'pecado' : 'gray'}
                    onClick={() => patchQuery({ group: undefined })}
                >
                    Строками
                </Button>

                {groups.map((item) => (
                    <Button
                        key={item.value}
                        size="xs"
                        variant={group === item.value ? 'solid' : 'outline'}
                        colorPalette={group === item.value ? 'pecado' : 'gray'}
                        onClick={() => patchQuery({ group: item.value })}
                    >
                        {item.label}
                    </Button>
                ))}
            </HStack>

            {group === '' ? (
                <>
                    <Flex justify="space-between" align="baseline" mb={2} gap={3} wrap="wrap">
                        <Text fontWeight="600">Строки к взысканию</Text>

                        <HStack gap={2} wrap="wrap">
                            <Text fontSize="xs" color="fg.muted">Сортировка:</Text>

                            {[
                                { key: 'due_date', label: 'по сроку' },
                                { key: 'unpaid', label: 'по сумме' },
                                { key: 'client', label: 'по партнёру' },
                            ].map((item) => (
                                <Button
                                    key={item.key}
                                    size="xs"
                                    variant={sort.column === item.key ? 'solid' : 'outline'}
                                    colorPalette={sort.column === item.key ? 'pecado' : 'gray'}
                                    onClick={() => changeSort(item.key)}
                                >
                                    {item.label}
                                    {sort.column === item.key && (sort.direction === 'asc' ? ' ↑' : ' ↓')}
                                </Button>
                            ))}
                        </HStack>
                    </Flex>

                    <FinanceRowsTable rows={rows} emptyMessage="Просроченных платежей по этому отбору нет" />
                </>
            ) : (
                <GroupTree
                    rows={groupRows ?? []}
                    total={totals.amount || 0}
                    onDrill={(row) => {
                        if (row.axis === 'manager') patchQuery({ manager_ids: [row.entity_id], group: undefined });
                        if (row.axis === 'organization') patchQuery({ organization_ids: [row.entity_id], group: undefined });
                    }}
                    onTask={can('crm-tasks.create') ? setTaskFor : null}
                    expanded={expanded}
                    onToggle={toggle}
                />
            )}

            {taskFor && (
                <TaskDialog
                    open
                    onClose={() => setTaskFor(null)}
                    entity={{ type: 'client', id: taskFor.entity_id }}
                    initialTitle={`Просрочка: ${taskFor.title} — ${formatRub(taskFor.overdue_debt)}`}
                    onSaved={() => setTaskFor(null)}
                />
            )}

        </CrmLayout>
    );
}

/**
 * Разрез просрочки: то же дерево, что в балансах, но о долге, который уже пора
 * требовать.
 *
 * Рядом с просроченной суммой всегда стоит общий долг узла и доля просрочки
 * в нём: сто тысяч просрочки при долге в сто двадцать тысяч и при долге в пять
 * миллионов — разные новости, и одна лишь абсолютная сумма их не различает.
 */
function GroupTree({ rows, total, onDrill, onTask, expanded, onToggle }) {
    return (
        <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflow="hidden">
            <Box overflowX="auto">
                <Table.Root size="sm" variant="line">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader width="40px" />
                            <Table.ColumnHeader>Строка разреза</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="end">Просрочено</Table.ColumnHeader>
                            <Table.ColumnHeader width="150px">
                                <HStack gap={1}>
                                    <Text fontSize="xs">Доля в просрочке</Text>
                                    <MetricHint text="Какую часть всей просроченной суммы даёт эта строка." />
                                </HStack>
                            </Table.ColumnHeader>
                            <Table.ColumnHeader width="170px">
                                <HStack gap={1}>
                                    <Text fontSize="xs">Просрочено от долга</Text>
                                    <MetricHint text="Доля просрочки в общем долге этой строки: сальдо взаиморасчётов берётся по тем же движениям, что в разделе «Балансы». Долг без просрочки в разрез не попадает вовсе." />
                                </HStack>
                            </Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="end">Строк</Table.ColumnHeader>
                            <Table.ColumnHeader>Самая давняя</Table.ColumnHeader>
                            <Table.ColumnHeader />
                        </Table.Row>
                    </Table.Header>

                    <Table.Body>
                        {rows.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={8}>
                                    <Text py={8} textAlign="center" color="fg.muted">
                                        Просроченных платежей по этому отбору нет
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}

                        {rows.map((row) => (
                            <GroupRows
                                key={row.id}
                                row={row}
                                depth={0}
                                total={total}
                                onDrill={onDrill}
                                onTask={onTask}
                                expanded={expanded}
                                onToggle={onToggle}
                            />
                        ))}
                    </Table.Body>
                </Table.Root>
            </Box>
        </Box>
    );
}

/** Узел дерева и его потомки — одной рекурсивной строкой на любом уровне. */
function GroupRows({ row, depth, total, onDrill, onTask, expanded, onToggle }) {
    const hasChildren = (row.children?.length ?? 0) > 0;
    const isOpen = !! expanded[row.id];

    const debt = Math.abs(row.current_balance || 0);
    // Долга может не быть вовсе: просрочка живёт на одной нашей организации,
    // а движения проведены на другой. Считать долю от нуля нечестно — прочерк.
    const debtShare = debt > 0 ? Math.round((row.overdue_debt / debt) * 100) : null;
    const share = total > 0 ? Math.round((row.overdue_debt / total) * 100) : 0;

    // Задача ставится на партнёра: у контрагента и нашей организации карточки
    // клиента нет, и вешать дебиторку не на кого.
    const taskable = row.axis === 'partner' && row.entity_id != null;
    const drillable = (row.axis === 'manager' || row.axis === 'organization') && row.entity_id != null;

    return (
        <Fragment>
            <Table.Row _hover={{ bg: 'bg.muted' }} bg={depth > 0 ? 'bg.subtle' : undefined}>
                <Table.Cell>
                    {hasChildren && (
                        <Button
                            size="xs"
                            variant="ghost"
                            onClick={() => onToggle(row.id)}
                            aria-label={isOpen ? 'Свернуть' : 'Развернуть'}
                        >
                            {isOpen ? <LuChevronDown /> : <LuChevronRight />}
                        </Button>
                    )}
                </Table.Cell>

                <Table.Cell>
                    <VStack align="start" gap={0} pl={depth * 4}>
                        {row.url ? (
                            <Box
                                as="a"
                                href={row.url}
                                fontSize="sm"
                                fontWeight={depth === 0 ? '600' : '400'}
                                _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                            >
                                {row.title}
                            </Box>
                        ) : (
                            <Text fontSize="sm" fontWeight={depth === 0 ? '600' : '400'}>{row.title}</Text>
                        )}

                        {(row.subtitle || row.manager_name) && (
                            <HStack gap={1} color="fg.muted" fontSize="10px">
                                {row.subtitle && <Text>{row.subtitle}</Text>}
                                {row.subtitle && row.manager_name && <Text>·</Text>}
                                {row.manager_name && <Text>{row.manager_name}</Text>}
                            </HStack>
                        )}
                    </VStack>
                </Table.Cell>

                <Table.Cell textAlign="end">
                    <Text fontSize="sm" fontWeight="600" color="red.fg" whiteSpace="nowrap">
                        {formatRub(row.overdue_debt)}
                    </Text>
                </Table.Cell>

                <Table.Cell>
                    <ShareBar value={share} tone="red" caption={`${share}%`} />
                </Table.Cell>

                <Table.Cell>
                    <VStack align="stretch" gap={0}>
                        <ShareBar
                            value={debtShare ?? 0}
                            tone={debtShare !== null && debtShare >= 50 ? 'red' : 'orange'}
                            caption={debtShare === null ? '—' : `${debtShare}%`}
                        />
                        <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">
                            общий долг {debt > 0 ? formatRub(debt) : '—'}
                        </Text>
                    </VStack>
                </Table.Cell>

                <Table.Cell textAlign="end">
                    <Text fontSize="sm">{row.overdue_lines}</Text>
                </Table.Cell>

                <Table.Cell>
                    <VStack align="start" gap={0}>
                        <Text fontSize="sm" whiteSpace="nowrap">{row.oldest_due ?? '—'}</Text>
                        {row.days_overdue > 0 && (
                            <Text fontSize="10px" color="red.fg" whiteSpace="nowrap">
                                {row.days_overdue} дн. назад
                            </Text>
                        )}
                    </VStack>
                </Table.Cell>

                <Table.Cell>
                    <HStack gap={1}>
                        {drillable && (
                            <Button size="xs" variant="ghost" onClick={() => onDrill(row)}>
                                Строки
                            </Button>
                        )}

                        {taskable && onTask && (
                            <Button
                                size="xs"
                                variant="ghost"
                                onClick={() => onTask(row)}
                                title="Поставить задачу по дебиторке"
                            >
                                <LuListPlus /> Задача
                            </Button>
                        )}
                    </HStack>
                </Table.Cell>
            </Table.Row>

            {isOpen && row.children.map((child) => (
                <GroupRows
                    key={child.id}
                    row={child}
                    depth={depth + 1}
                    total={total}
                    onDrill={onDrill}
                    onTask={onTask}
                    expanded={expanded}
                    onToggle={onToggle}
                />
            ))}
        </Fragment>
    );
}

/**
 * Доля полосой: длина читается быстрее числа, а число нужно для точности —
 * поэтому рядом и то и другое.
 */
function ShareBar({ value, tone = 'red', caption, width }) {
    return (
        <HStack gap={2} width={width}>
            <Box bg="bg.muted" borderRadius="full" height="6px" flex="1" minW="40px" overflow="hidden">
                <Box
                    bg={tone === 'red' ? 'red.solid' : 'orange.solid'}
                    height="6px"
                    width={`${Math.min(100, Math.max(value > 0 ? 2 : 0, value))}%`}
                />
            </Box>
            {caption !== undefined && (
                <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">{caption}</Text>
            )}
        </HStack>
    );
}

/** Одно число в компактной ленте итогов. */
const Metric = ({ label, value, tone, hint, after }) => (
    <HStack gap={2} align="baseline">
        <VStack align="start" gap={0}>
            <HStack gap={1}>
                <Text fontSize="10px" color="fg.muted" textTransform="uppercase" letterSpacing="0.03em">{label}</Text>
                {hint && <MetricHint text={hint} />}
            </HStack>
            <HStack gap={2} align="center">
                <Text fontSize="md" fontWeight="700" color={tone === 'red' ? 'red.fg' : undefined} whiteSpace="nowrap">
                    {value}
                </Text>
                {after}
            </HStack>
        </VStack>
    </HStack>
);
