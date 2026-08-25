import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Input, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { Button } from '@/components/ui/button';
import { Tooltip } from '@/components/ui/tooltip';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import { LuInfo } from 'react-icons/lu';
import { localDate } from '@/shared/localDate';

/**
 * Журнал недоборов: что, у кого и на какую сумму отменилось.
 *
 * Замен сайт не предлагает — отмену делает и склад при сборке, и сам клиент
 * через менеджера. Поэтому экран отвечает на три вопроса: список отмен,
 * повторяемость по партнёрам и повторяемость по товарам.
 *
 * Причина отмены в протоколе 1С не приходит, поэтому метку ставит менеджер
 * прямо из строки, а подсказка по расходному ордеру лишь помогает не гадать.
 */
const TABS = [
    { value: 'log', label: 'Журнал' },
    { value: 'partners', label: 'По партнёрам' },
    { value: 'products', label: 'По товарам' },
];

const STATES = [
    { value: 'active', label: 'В работе' },
    { value: 'archived', label: 'Архив' },
    { value: 'all', label: 'Все' },
];

const PERIODS = [
    { days: 30, label: '30 дней' },
    { days: 90, label: '90 дней' },
    { days: 365, label: 'Год' },
];

const money = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value || 0);

const isoDate = (date) => localDate(date);

const shiftDays = (days) => {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return isoDate(date);
};

/**
 * Подсказка «кто отменил»: расходный ордер по заказу — единственный след,
 * по которому складскую отмену можно отличить от отказа клиента.
 */
function SourceHint({ hint }) {
    if (!hint) {
        return null;
    }

    const color = hint.kind === 'none' ? 'fg.muted' : 'orange.fg';
    const issues = (hint.issues || [])
        .map((issue) => `${issue.number} от ${issue.date ?? '—'} (${issue.status_label})`)
        .join('; ');

    return (
        <Tooltip content={issues ? `${hint.description} Ордера: ${issues}` : hint.description} openDelay={300}>
            <HStack gap={1} color={color} fontSize="xs" cursor="help">
                <LuInfo size={12} style={{ flexShrink: 0 }} />
                <Text>{hint.label}</Text>
            </HStack>
        </Tooltip>
    );
}

/**
 * Метка источника отмены: две кнопки-переключателя. Повторный клик по активной
 * снимает метку — ошибочная разметка не должна оставаться навсегда.
 */
function SourceCell({ row, options, canEdit, onSet }) {
    if (!canEdit) {
        return row.source ? (
            <Badge colorPalette={row.source_color} variant="subtle">{row.source_label}</Badge>
        ) : (
            <Text color="fg.muted" fontSize="sm">не размечено</Text>
        );
    }

    return (
        <HStack gap={1}>
            {options.map((option) => {
                const active = row.source === option.value;

                return (
                    <Button
                        key={option.value}
                        size="2xs"
                        variant={active ? 'solid' : 'outline'}
                        colorPalette={active ? option.color : 'gray'}
                        onClick={() => onSet(row, active ? null : option.value)}
                    >
                        {option.short_label}
                    </Button>
                );
            })}
        </HStack>
    );
}

/**
 * Комментарий к отмене: сохраняется по Enter или уходу фокуса — отдельная
 * кнопка «Сохранить» на полсотни строк только мешала бы.
 */
function NoteCell({ row, canEdit, onSave }) {
    const [value, setValue] = useState(row.note ?? '');

    if (!canEdit) {
        return row.note ? <Text fontSize="sm">{row.note}</Text> : <Text color="fg.muted">—</Text>;
    }

    const commit = () => {
        if ((row.note ?? '') !== value) {
            onSave(row, value);
        }
    };

    return (
        <Input
            size="xs"
            variant="subtle"
            placeholder="Комментарий"
            value={value}
            maxLength={500}
            onChange={(event) => setValue(event.target.value)}
            onBlur={commit}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.currentTarget.blur();
                }
            }}
        />
    );
}

export default function Index({
    rows,
    totals,
    partners = [],
    products = [],
    filters,
    sourceOptions = [],
    managers = [],
    canSeeAll = false,
    canEdit = false,
}) {
    const apply = (patch) => {
        router.get('/crm/shortages', { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const setSource = (row, source) => {
        router.post(`/crm/shortages/${row.id}/source`, { source, note: row.note ?? null }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const setNote = (row, note) => {
        router.post(`/crm/shortages/${row.id}/source`, { source: row.source ?? null, note }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const reset = () => {
        router.get('/crm/shortages', { tab: filters.tab, scope: filters.scope, state: filters.state }, { replace: true });
    };

    const logColumns = [
        {
            key: 'cancelled_at',
            label: 'Отменено',
            render: (value, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{value ?? '—'}</Text>
                    {row.order_date && (
                        <Text fontSize="xs" color="fg.muted">заказ от {row.order_date}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'order_number',
            label: 'Заказ',
            render: (value, row) => (
                <Link href={`/crm/orders/${row.order_id}`}>
                    <Text fontWeight="medium" _hover={{ textDecoration: 'underline' }}>{value || '—'}</Text>
                </Link>
            ),
        },
        {
            key: 'client',
            label: 'Партнёр',
            render: (value, row) => (
                <VStack align="start" gap={0}>
                    {row.client_id ? (
                        <Link href={`/crm/partners/${row.client_id}`}>
                            <Text fontSize="sm" _hover={{ textDecoration: 'underline' }}>{value}</Text>
                        </Link>
                    ) : (
                        <Text fontSize="sm">{value}</Text>
                    )}
                    {row.company && <Text fontSize="xs" color="fg.muted">{row.company}</Text>}
                </VStack>
            ),
        },
        { key: 'manager', label: 'Менеджер' },
        {
            key: 'product',
            label: 'Товар',
            render: (value, row) => (
                <VStack align="start" gap={0}>
                    {row.slug ? (
                        <Link href={`/products/${row.slug}`}>
                            <Text fontSize="sm" _hover={{ textDecoration: 'underline' }}>{value}</Text>
                        </Link>
                    ) : (
                        <Text fontSize="sm">{value}</Text>
                    )}
                    {row.sku && <Text fontSize="xs" color="fg.muted">арт. {row.sku}</Text>}
                </VStack>
            ),
        },
        { key: 'quantity', label: 'Кол-во' },
        {
            key: 'amount',
            label: 'Сумма, ₽',
            render: (value) => <Text>{money(value)}</Text>,
        },
        {
            key: 'source',
            label: 'Кто отменил',
            render: (_value, row) => (
                <VStack align="start" gap={1}>
                    {row.archived_at && (
                        <Badge colorPalette="gray" variant="outline">архив от {row.archived_at}</Badge>
                    )}
                    <SourceCell row={row} options={sourceOptions} canEdit={canEdit} onSet={setSource} />
                    {row.source_user && (
                        <Text fontSize="xs" color="fg.muted">{row.source_user}, {row.source_at}</Text>
                    )}
                    {!row.source && <SourceHint hint={row.hint} />}
                </VStack>
            ),
        },
        {
            key: 'note',
            label: 'Комментарий',
            render: (_value, row) => <NoteCell row={row} canEdit={canEdit} onSave={setNote} />,
        },
    ];

    const partnerColumns = [
        {
            key: 'name',
            label: 'Партнёр',
            render: (value, row) => (row.user_id ? (
                <Link href={`/crm/partners/${row.user_id}`}>
                    <Text _hover={{ textDecoration: 'underline' }}>{value}</Text>
                </Link>
            ) : value),
        },
        { key: 'manager', label: 'Менеджер' },
        { key: 'lines_count', label: 'Отмен' },
        { key: 'orders_count', label: 'Заказов' },
        { key: 'quantity', label: 'Штук' },
        { key: 'amount', label: 'Сумма, ₽', render: (value) => money(value) },
        {
            key: 'warehouse_count',
            label: 'Склад / клиент',
            render: (_value, row) => (
                <Text fontSize="sm">{row.warehouse_count} / {row.client_count}</Text>
            ),
        },
        { key: 'last_cancelled_at', label: 'Последняя' },
    ];

    const productColumns = [
        {
            key: 'name',
            label: 'Товар',
            render: (value, row) => (
                <VStack align="start" gap={0}>
                    {row.slug ? (
                        <Link href={`/products/${row.slug}`}>
                            <Text fontSize="sm" _hover={{ textDecoration: 'underline' }}>{value}</Text>
                        </Link>
                    ) : (
                        <Text fontSize="sm">{value}</Text>
                    )}
                    {row.sku && <Text fontSize="xs" color="fg.muted">арт. {row.sku}</Text>}
                </VStack>
            ),
        },
        { key: 'lines_count', label: 'Отмен' },
        { key: 'partners_count', label: 'Партнёров' },
        { key: 'quantity', label: 'Штук' },
        { key: 'amount', label: 'Сумма, ₽', render: (value) => money(value) },
        {
            key: 'warehouse_count',
            label: 'Склад / клиент',
            render: (_value, row) => (
                <Text fontSize="sm">{row.warehouse_count} / {row.client_count}</Text>
            ),
        },
        { key: 'last_cancelled_at', label: 'Последняя' },
    ];

    const hasFilters = Boolean(
        filters.search || filters.source || filters.manager_id || filters.user_id || filters.product_id
    );

    return (
        <>
            <Head title="Недоборы — CRM" />

            <PageHeader
                title="Недоборы"
                description="Журнал отменённых строк заказов: что, у кого и на какую сумму не уехало"
            />

            <VStack align="stretch" gap={4}>
                <Flex gap={3} align="center" wrap="wrap">
                    <Box flex="1" minW="240px">
                        <SearchInput
                            value={filters.search ?? ''}
                            onChange={(value) => apply({ search: value || undefined })}
                            placeholder="Номер заказа, товар или партнёр..."
                        />
                    </Box>

                    <HStack gap={1}>
                        <Input
                            size="sm"
                            type="date"
                            maxW="150px"
                            value={filters.from}
                            onChange={(event) => apply({ from: event.target.value })}
                        />
                        <Text color="fg.muted">—</Text>
                        <Input
                            size="sm"
                            type="date"
                            maxW="150px"
                            value={filters.to}
                            onChange={(event) => apply({ to: event.target.value })}
                        />
                    </HStack>

                    <HStack gap={1}>
                        {PERIODS.map((period) => (
                            <Button
                                key={period.days}
                                size="xs"
                                variant="ghost"
                                onClick={() => apply({ from: shiftDays(period.days), to: isoDate(new Date()) })}
                            >
                                {period.label}
                            </Button>
                        ))}
                    </HStack>

                    <Box minW="180px">
                        <NativeSelectRoot size="sm">
                            <NativeSelectField
                                value={filters.source ?? ''}
                                onChange={(event) => apply({ source: event.target.value || undefined })}
                            >
                                <option value="">Кто отменил — любой</option>
                                {sourceOptions.map((option) => (
                                    <option key={option.value} value={option.value}>{option.label}</option>
                                ))}
                                <option value="none">Не размечено</option>
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </Box>

                    {canSeeAll && managers.length > 0 && (
                        <Box minW="200px">
                            <NativeSelectRoot size="sm">
                                <NativeSelectField
                                    value={filters.manager_id ? String(filters.manager_id) : ''}
                                    onChange={(event) => apply({ manager_id: event.target.value || undefined })}
                                >
                                    <option value="">Менеджер — любой</option>
                                    {managers.map((manager) => (
                                        <option key={manager.value} value={String(manager.value)}>{manager.label}</option>
                                    ))}
                                </NativeSelectField>
                            </NativeSelectRoot>
                        </Box>
                    )}

                    <ScopeToggle section="shortages" scope={filters.scope} available={canSeeAll} label="Только мои" />

                    {hasFilters && (
                        <Button size="sm" variant="outline" onClick={reset}>Сбросить</Button>
                    )}
                </Flex>

                <HStack gap={4} wrap="wrap" borderWidth="1px" borderRadius="lg" p={3} bg="bg.subtle">
                    <Text fontSize="sm">Отмен: <b>{totals.lines_count}</b></Text>
                    <Text fontSize="sm">Штук: <b>{totals.quantity}</b></Text>
                    <Text fontSize="sm">Сумма: <b>{money(totals.amount)} ₽</b></Text>
                    <Badge colorPalette="orange" variant="subtle">Склад: {totals.warehouse_count}</Badge>
                    <Badge colorPalette="purple" variant="subtle">Клиент: {totals.client_count}</Badge>
                    <Badge colorPalette="gray" variant="subtle">Без метки: {totals.unmarked_count}</Badge>
                </HStack>

                <HStack gap={2} wrap="wrap" justify="space-between">
                    <HStack gap={2}>
                        {TABS.map((tab) => (
                            <Button
                                key={tab.value}
                                size="xs"
                                variant={filters.tab === tab.value ? 'solid' : 'outline'}
                                onClick={() => apply({ tab: tab.value })}
                            >
                                {tab.label}
                            </Button>
                        ))}
                    </HStack>

                    {/* Архив — отмены, выведенные из работы без разметки: разбирать
                        их задним числом никто не будет, но в сводках они остаются. */}
                    <HStack gap={1}>
                        {STATES.map((state) => (
                            <Button
                                key={state.value}
                                size="xs"
                                variant={filters.state === state.value ? 'subtle' : 'ghost'}
                                onClick={() => apply({ state: state.value })}
                            >
                                {state.label}
                            </Button>
                        ))}
                    </HStack>
                </HStack>

                {filters.tab === 'log' && (
                    <DataTable data={rows.data || []} columns={logColumns} pagination={rows} />
                )}

                {filters.tab === 'partners' && (
                    <DataTable data={partners} columns={partnerColumns} />
                )}

                {filters.tab === 'products' && (
                    <DataTable data={products} columns={productColumns} />
                )}

                <Text fontSize="xs" color="fg.muted">
                    Строка попадает в журнал, когда 1С присылает её отменённой в order.updated. Причину 1С не
                    передаёт: позицию снимает и склад при закрытии расходного ордера, и менеджер по просьбе
                    клиента — поэтому метку ставит человек, а подсказка лишь показывает, был ли по заказу
                    расходный ордер.
                </Text>
            </VStack>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
