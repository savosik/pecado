import { useCallback, useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, Card, HStack, Input, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuCheckCheck, LuFileDown, LuSlidersHorizontal } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Button } from '@/components/ui/button';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import RowActions from '@/shared/Panel/RowActions';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import {
    TaxRegimeDialog,
    TaxRegimeFreshnessBadge,
    TaxRegimeShiftBadge,
    VatPreferenceBadge,
    confirmTaxRegime,
} from '@/Crm/Components/ContractorTaxRegime';

/**
 * Селект отбора: пустое значение — «неважно» и уходит из запроса.
 */
function FilterSelect({ value, onChange, placeholder, options, minW = '170px' }) {
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
                    {placeholder !== null && <option value="">{placeholder}</option>}
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </NativeSelectField>
            </NativeSelectRoot>
        </Box>
    );
}

function SearchInput({ value, onCommit }) {
    const [draft, setDraft] = useState(value ?? '');

    useEffect(() => setDraft(value ?? ''), [value]);

    const commit = () => {
        const next = draft.trim() || undefined;

        if ((next ?? '') !== (value ?? '')) {
            onCommit(next);
        }
    };

    return (
        <Input
            size="xs"
            maxW="260px"
            bg="bg"
            value={draft}
            placeholder="Юрлицо, ИНН или партнёр"
            onChange={(event) => setDraft(event.target.value)}
            onBlur={commit}
            onKeyDown={(event) => event.key === 'Enter' && commit()}
        />
    );
}

/**
 * Плитка сводки. Клик включает отбор, повторный — снимает.
 */
function StatTile({ label, value, hint, color, active, onClick }) {
    return (
        <Card.Root
            size="sm"
            variant="outline"
            cursor="pointer"
            borderColor={active ? `${color}.500` : undefined}
            borderWidth={active ? '2px' : '1px'}
            onClick={onClick}
        >
            <Card.Body>
                <Text fontSize="xs" color="fg.muted">{label}</Text>
                <Text fontSize="2xl" fontWeight="700" color={`${color}.600`}>{value}</Text>
                {hint && <Text fontSize="xs" color="fg.muted">{hint}</Text>}
            </Card.Body>
        </Card.Root>
    );
}

function percent(part, total) {
    return total > 0 ? Math.round((part / total) * 100) : 0;
}

export default function Index({
    rows,
    summary,
    filters,
    options,
    managers = [],
    canSeeAll = false,
    canEdit = false,
}) {
    const [editing, setEditing] = useState(null);
    const year = summary.target_year;
    const { totals } = summary;

    const applyFilters = useCallback((patch) => {
        router.get(route('crm.tax-regimes.index'), { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [filters]);

    const toggle = (key, value) => applyFilters({ [key]: filters[key] === value ? undefined : value });

    const exportParams = Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    );

    const columns = [
        {
            key: 'name',
            label: 'Юрлицо',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontWeight="semibold">{row.name}</Text>
                    <Text fontSize="xs" fontFamily="mono" color="fg.muted">
                        {row.tax_id ? `ИНН ${row.tax_id}` : 'ИНН не указан'}
                    </Text>
                </VStack>
            ),
        },
        {
            key: 'partner',
            label: 'Партнёр',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{row.partner?.name ?? '—'}</Text>
                    {canSeeAll && row.manager && (
                        <Text fontSize="xs" color="fg.muted">{row.manager}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'current',
            label: 'Сейчас',
            render: (_, row) => (
                <Text fontSize="sm" color={row.tax_regime.current ? undefined : 'fg.muted'}>
                    {row.tax_regime.current?.label ?? '—'}
                </Text>
            ),
        },
        {
            key: 'planned',
            label: `В ${year} году`,
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    <Text fontSize="sm" color={row.tax_regime.shift ? undefined : 'fg.muted'}>
                        {row.tax_regime.shift ? row.tax_regime.planned.label : '—'}
                    </Text>
                    <HStack gap={1} wrap="wrap">
                        <TaxRegimeShiftBadge regime={row.tax_regime} />
                        <VatPreferenceBadge regime={row.tax_regime} />
                    </HStack>
                </VStack>
            ),
        },
        {
            key: 'freshness',
            label: 'Ответ',
            render: (_, row) => (
                <VStack align="start" gap={0.5}>
                    <TaxRegimeFreshnessBadge regime={row.tax_regime} />
                    {row.tax_regime.confirmed_at && (
                        <Text fontSize="xs" color="fg.muted">
                            {row.tax_regime.confirmed_at}
                            {row.tax_regime.confirmed_by && ` · ${row.tax_regime.confirmed_by}`}
                        </Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    size="xs"
                    view={{ href: route('crm.contractors.show', row.id), label: 'Открыть карточку юрлица' }}
                    edit={canEdit ? { onClick: () => setEditing(row), label: 'Заполнить налоговый режим' } : null}
                    extra={[{
                        icon: LuCheckCheck,
                        label: 'Подтвердить без изменений',
                        allowed: canEdit && row.tax_regime.can_confirm && row.tax_regime.freshness.value !== 'fresh',
                        onClick: () => confirmTaxRegime(row.id),
                    }]}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Налоговые режимы" />
            <PageHeader
                title="Налоговые режимы"
                description={`На какой системе налогообложения юрлица партнёров работают сейчас и на какой будут в ${year} году — для оценки рисков перехода клиентов на НДС`}
                actions={(
                    <Button asChild size="sm" variant="outline">
                        <a href={route('crm.tax-regimes.export', exportParams)}>
                            <LuFileDown /> Выгрузить в Excel
                        </a>
                    </Button>
                )}
            />

            <Text fontSize="xs" color="fg.muted" mb={4}>
                Ответ действует {options.confirm_days} дней (если партнёр не решил — {options.undecided_confirm_days})
                и в любом случае устаревает 1 января {year} года. По понедельникам менеджер получает задачу по покупающим
                партнёрам без актуального ответа; задача закрывается сама, когда ответы собраны.
            </Text>

            <SimpleGrid columns={{ base: 2, md: 4 }} gap={3} mb={3}>
                <StatTile
                    label="Юрлиц в реестре"
                    value={totals.total}
                    hint={filters.active === '1' ? `покупали за ${options.active_days} дней` : 'все юрлица партнёров'}
                    color="gray"
                    active={false}
                    onClick={() => applyFilters({ freshness: undefined, shift: undefined })}
                />
                <StatTile
                    label="Актуально"
                    value={totals.fresh}
                    hint={`${percent(totals.fresh, totals.total)} % собрано`}
                    color="green"
                    active={filters.freshness === 'fresh'}
                    onClick={() => toggle('freshness', 'fresh')}
                />
                <StatTile
                    label="Нужно подтвердить"
                    value={totals.outdated}
                    color="orange"
                    active={filters.freshness === 'outdated'}
                    onClick={() => toggle('freshness', 'outdated')}
                />
                <StatTile
                    label="Не заполнено"
                    value={totals.missing}
                    color="red"
                    active={filters.freshness === 'missing'}
                    onClick={() => toggle('freshness', 'missing')}
                />
            </SimpleGrid>

            <HStack gap={2} wrap="wrap" mb={4}>
                <Text fontSize="xs" color="fg.muted">План на {year}:</Text>
                {summary.shifts.map((shift) => (
                    <Badge
                        key={shift.value}
                        colorPalette={shift.color}
                        variant={filters.shift === shift.value ? 'solid' : 'subtle'}
                        cursor="pointer"
                        onClick={() => toggle('shift', shift.value)}
                    >
                        {shift.label}: {shift.count}
                    </Badge>
                ))}
            </HStack>

            {summary.managers && summary.managers.length > 0 && (
                <Card.Root size="sm" mb={4}>
                    <Card.Body>
                        <Text fontSize="sm" fontWeight="600" mb={2}>Кто сколько собрал</Text>
                        <Box overflowX="auto">
                            <Table.Root size="sm" variant="line">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Менеджер</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Юрлиц</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Актуально</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Нужно подтвердить</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Не заполнено</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Собрано</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {summary.managers.map((manager) => (
                                        <Table.Row key={manager.name}>
                                            <Table.Cell>{manager.name}</Table.Cell>
                                            <Table.Cell textAlign="end">{manager.total}</Table.Cell>
                                            <Table.Cell textAlign="end">{manager.fresh}</Table.Cell>
                                            <Table.Cell textAlign="end">{manager.outdated}</Table.Cell>
                                            <Table.Cell textAlign="end">{manager.missing}</Table.Cell>
                                            <Table.Cell textAlign="end" fontWeight="600">
                                                {percent(manager.fresh, manager.total)} %
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Card.Body>
                </Card.Root>
            )}

            <HStack gap={2} align="center" wrap="wrap" mb={4}>
                <HStack gap={1} color="fg.muted" pr={1}>
                    <LuSlidersHorizontal size={13} />
                    <Text fontSize="xs" whiteSpace="nowrap">Уточнить</Text>
                </HStack>

                <ScopeToggle section="tax-regimes" scope={filters.scope} available={canSeeAll} />

                <SearchInput value={filters.search} onCommit={(value) => applyFilters({ search: value })} />

                <FilterSelect
                    value={filters.active}
                    onChange={(value) => applyFilters({ active: value ?? '1' })}
                    placeholder={null}
                    minW="200px"
                    options={[
                        { value: '1', label: `Покупали за ${options.active_days} дней` },
                        { value: '0', label: 'Все юрлица партнёров' },
                    ]}
                />

                {managers.length > 0 && (
                    <FilterSelect
                        value={filters.manager_id ? String(filters.manager_id) : undefined}
                        onChange={(value) => applyFilters({ manager_id: value })}
                        placeholder="Все менеджеры"
                        options={managers.map((manager) => ({ value: String(manager.id), label: manager.name }))}
                    />
                )}

                <FilterSelect
                    value={filters.current_regime}
                    onChange={(value) => applyFilters({ current_regime: value })}
                    placeholder="Сейчас: любой режим"
                    minW="200px"
                    options={options.current}
                />

                <FilterSelect
                    value={filters.planned_regime}
                    onChange={(value) => applyFilters({ planned_regime: value })}
                    placeholder={`В ${year}: любой режим`}
                    minW="200px"
                    options={options.planned}
                />
            </HStack>

            <DataTable
                data={rows.data}
                columns={columns}
                pagination={rows}
                perPage={filters.per_page}
                onPerPageChange={(perPage) => applyFilters({ per_page: perPage })}
                emptyMessage="Юрлица не найдены"
            />

            <TaxRegimeDialog
                contractorId={editing?.id}
                contractorName={editing?.name}
                regime={editing?.tax_regime}
                options={options}
                open={editing !== null}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
