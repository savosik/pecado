import { Head, router } from '@inertiajs/react';
import { Box, Flex, Grid, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import AmountFilterInput from '@/Crm/Components/AmountFilterInput';
import MetricHint from '@/Crm/Components/MetricHint';
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

            <SimpleGrid columns={{ base: 1, md: 4 }} gap={3} mb={4}>
                <Tile
                    label="Просрочено"
                    value={formatRub(totals.amount)}
                    tone="red"
                    hint="Сумма непогашенных остатков по строкам графика, срок которых прошёл. Учитывает все фильтры сверху, включая корзину задержки и порог остатка. Планы по заказам сюда не входят: долг создаёт отгрузка, а не заказ."
                />
                <Tile
                    label="Строк графика"
                    value={String(totals.lines || 0)}
                    hint="Сколько строк графика просрочено. У одной реализации строк бывает несколько — по этапам оплаты, поэтому строк всегда не меньше, чем документов."
                />
                <Tile
                    label="Документов"
                    value={String(totals.documents || 0)}
                    hint="Сколько разных реализаций стоит за этими строками."
                />
                <Tile
                    label="Партнёров"
                    value={String(totals.clients || 0)}
                    hint="Сколько партнёров имеют хотя бы одну просроченную строку. Это те, кому предстоит звонить."
                />
            </SimpleGrid>

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
                <GroupTable
                    rows={groupRows ?? []}
                    axis={group}
                    total={totals.amount || 0}
                    onDrill={(row) => {
                        if (group === 'manager') patchQuery({ manager_ids: [row.entity_id], group: undefined });
                        if (group === 'organization') patchQuery({ organization_ids: [row.entity_id], group: undefined });
                    }}
                />
            )}
        </CrmLayout>
    );
}

/**
 * Сводка по выбранной оси: одна строка на сущность.
 *
 * Доля рисуется полосой, а не процентом: раздел открывают, чтобы понять,
 * с кого начинать, и на глаз длина полосы отвечает на это быстрее числа.
 */
function GroupTable({ rows, axis, total, onDrill }) {
    const drillable = axis === 'manager' || axis === 'organization';

    return (
        <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflow="hidden">
            <Box overflowX="auto">
                <Table.Root size="sm" variant="line">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader>{AXIS_LABELS[axis]}</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="end">Просрочено</Table.ColumnHeader>
                            <Table.ColumnHeader width="140px">Доля</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="end">Строк</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="end">Партнёров</Table.ColumnHeader>
                            <Table.ColumnHeader>Самая давняя</Table.ColumnHeader>
                            <Table.ColumnHeader />
                        </Table.Row>
                    </Table.Header>

                    <Table.Body>
                        {rows.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={7}>
                                    <Text py={8} textAlign="center" color="fg.muted">
                                        Просроченных платежей по этому отбору нет
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}

                        {rows.map((row) => (
                            <Table.Row key={row.key} _hover={{ bg: 'bg.muted' }}>
                                <Table.Cell>
                                    <VStack align="start" gap={0}>
                                        {row.url ? (
                                            <Box
                                                as="a"
                                                href={row.url}
                                                fontSize="sm"
                                                fontWeight="600"
                                                _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                                            >
                                                {row.title}
                                            </Box>
                                        ) : (
                                            <Text fontSize="sm" fontWeight="600">{row.title}</Text>
                                        )}
                                        {row.manager_name && (
                                            <Text fontSize="10px" color="fg.muted">{row.manager_name}</Text>
                                        )}
                                    </VStack>
                                </Table.Cell>

                                <Table.Cell textAlign="end">
                                    <Text fontSize="sm" fontWeight="600" color="red.fg" whiteSpace="nowrap">
                                        {formatRub(row.unpaid)}
                                    </Text>
                                </Table.Cell>

                                <Table.Cell>
                                    <Grid templateColumns="1fr auto" gap={2} alignItems="center">
                                        <Box bg="bg.muted" borderRadius="full" height="6px" overflow="hidden">
                                            <Box
                                                bg="red.solid"
                                                height="6px"
                                                width={`${total > 0 ? Math.max(2, Math.round((row.unpaid / total) * 100)) : 0}%`}
                                            />
                                        </Box>
                                        <Text fontSize="10px" color="fg.muted">
                                            {total > 0 ? Math.round((row.unpaid / total) * 100) : 0}%
                                        </Text>
                                    </Grid>
                                </Table.Cell>

                                <Table.Cell textAlign="end">
                                    <Text fontSize="sm">{row.lines_count}</Text>
                                </Table.Cell>

                                <Table.Cell textAlign="end">
                                    <Text fontSize="sm">{axis === 'partner' ? '—' : row.clients_count}</Text>
                                </Table.Cell>

                                <Table.Cell>
                                    <VStack align="start" gap={0}>
                                        <Text fontSize="sm">{row.oldest_due}</Text>
                                        <Text fontSize="10px" color="red.fg">{row.days_overdue} дн. назад</Text>
                                    </VStack>
                                </Table.Cell>

                                <Table.Cell>
                                    {drillable && row.entity_id != null && (
                                        <Button size="xs" variant="ghost" onClick={() => onDrill(row)}>
                                            Строки
                                        </Button>
                                    )}
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            </Box>
        </Box>
    );
}

const AXIS_LABELS = {
    partner: 'Партнёр',
    manager: 'Менеджер',
    organization: 'Наша организация',
    company: 'Контрагент',
};

const Tile = ({ label, value, tone, hint }) => (
    <Box borderWidth="1px" borderRadius="lg" p={4} bg={tone === 'red' ? 'red.subtle' : undefined}>
        <HStack gap={1}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            {hint && <MetricHint text={hint} />}
        </HStack>
        <Text fontSize="xl" fontWeight="700" mt={1} color={tone === 'red' ? 'red.fg' : undefined}>{value}</Text>
    </Box>
);
